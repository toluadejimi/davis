<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\WalletHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use JsonStringfy\JsonStringfy\Activereq\Activeck\WTC;
use Status;

class UserController extends Controller
{
    public function home()
    {
        $pageTitle = 'Dashboard';
        $user = auth()->user();

        $widget['total_wallet_amount'] = UserWallet::where('user_id', $user->id)->sum('balance');
        $widget['total_payments'] = Deposit::where('user_id', $user->id)->successful()->sum('amount');
        $widget['total_orders'] = Order::where('user_id', $user->id)->paid()->count();
        $widget['total_tickets'] = SupportTicket::where('user_id', $user->id)->count();

        //$latestDeposits = $user->deposits()->with('gateway', 'order')->orderBy('id','desc')->take(5)->get();

        $latestDeposits = Deposit::where('user_id', Auth::id())->orderBy('id','desc')->with('gateway', 'order')->paginate(getPaginate());



        return view($this->activeTemplate . 'user.dashboard', compact('pageTitle', 'user', 'widget', 'latestDeposits'));
    }

    public function depositHistory(Request $request)
    {
        $pageTitle = 'Payment History';
        $deposits = Deposit::where('user_id', Auth::id())->orderBy('id','desc')->with('gateway', 'order')->paginate(getPaginate());

        return view($this->activeTemplate.'user.deposit_history', compact('pageTitle', 'deposits'));
    }

    public function walletHistory(Request $request)
    {
        $pageTitle = 'Wallet History';
        $wallet = auth()->user()->wallet()->get()->first();

        $wallet_id = $wallet->id;

        $query = WalletHistory::where('wallet_id', "$wallet_id")->orderBy('id', 'desc');

        $deposits = $query->paginate(getPaginate());

        return view($this->activeTemplate.'user.wallet_history', compact('pageTitle', 'deposits'));
    }

    public function attachmentDownload($fileHash)
    {
        $filePath = decrypt($fileHash);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $general = gs();
        $title = slug($general->site_name).'- attachments.'.$extension;
        $mimetype = mime_content_type($filePath);
        header('Content-Disposition: attachment; filename="' . $title);
        header("Content-Type: " . $mimetype);
        return readfile($filePath);
    }

    public function userData()
    {
        $user = auth()->user();
        if ($user->profile_complete == 1) {
            return to_route('user.home');
        }
        $pageTitle = 'User Data';
        $info       = json_decode(json_encode(getIpInfo()), true);
        $mobileCode = @implode(',', $info['code']);
        $countries  = json_decode(file_get_contents(resource_path('views/partials/country.json')));
        return view($this->activeTemplate . 'user.user_data', compact('pageTitle', 'user', 'mobileCode', 'countries'));
    }

    public function userDataSubmit(Request $request)
    {
        $user = auth()->user();
        if ($user->profile_complete == 1) {
            return to_route('user.home');
        }

        $validationRule = [
            'firstname' => 'required',
            'lastname' => 'required',
        ];

        if ($user->login_by) {
            if (!$user->email) {
                $validationRule = array_merge($validationRule, [
                    'email' => 'required|string|email|unique:users',
                ]);
            }
            $countryData = (array)json_decode(file_get_contents(resource_path('views/partials/country.json')));
            $countryCodes = implode(',', array_keys($countryData));
            $mobileCodes = implode(',', array_column($countryData, 'dial_code'));
            $countries = implode(',', array_column($countryData, 'country'));
            $validationRule = array_merge($validationRule, [
                'mobile' => 'required|regex:/^([0-9]*)$/',
                'mobile_code' => 'required|in:' . $mobileCodes,
                'country_code' => 'required|in:' . $countryCodes,
                'country' => 'required|in:' . $countries,
            ]);
        }

        $request->validate($validationRule);
        $hasEmail = $user->email ? true : false;
        $general = gs();

        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;
        $user->address = [
            'country' => $user->login_by ? $request->country : @$user->address->country,
            'address' => $request->address,
            'state' => $request->state,
            'zip' => $request->zip,
            'city' => $request->city,
        ];
        $user->country_code = $request->country_code;
        $user->mobile = $request->mobile_code . $request->mobile;
        $user->profile_complete = 1;
        if (!$hasEmail) {
            $user->ev = $general->ev ? Status::NO : Status::YES;
            $user->email = $request->email;
        }
        $user->sv = $general->sv ? Status::NO : Status::YES;
        $user->save();

        $notify[] = ['success', 'Registration process completed successfully'];
        return to_route('user.home')->withNotify($notify);
    }

    public function orders(){
        $pageTitle = 'Orders';

        $orders = Order::where('user_id', auth()->id())
            ->where('status', Status::ORDER_PAID)
            ->searchable(['deposit:trx'])
            ->orderBy('id','desc')
            ->with('deposit', 'orderItems')
        ->paginate(getPaginate());

        return view($this->activeTemplate.'user.orders', compact('pageTitle', 'orders'));
    }

    public function orderDetails($id){
        $pageTitle = 'Order Details';
        $order = Order::where('user_id', auth()->id())->where('status', Status::ORDER_PAID)->findOrFail($id);
        $orderItems = OrderItem::whereIn('id', $order->orderItems->pluck('id') ?? [])->with('product', 'productDetail')->paginate(getPaginate());
        return view($this->activeTemplate.'user.order_details', compact('pageTitle', 'order', 'orderItems'));
    }


    public function e_fund(request $request){

        $get_user =  User::where('email', $request->email)->first() ?? null;

        if($get_user == null){

            return response()->json([
                'status' => false,
                'message' => 'No user found, please check email and try again',
            ]);
        }


        $amount = number_format($request->amount, 2);

        $get_depo = Deposit::where('trx', $request->order_id)->first() ?? null;
        if ($get_depo == null){
            $trx = new Deposit();
            $trx->trx = $request->order_id;
            $trx->status = 1;
            $trx->user_id = $get_user->id;
            $trx->amount = $request->amount;
            $trx->method_code = 210;
            $trx->save();
        }else{
            Deposit::where('trx', $request->order_id)->update(['status'=> 1]);
        }

        $get_user_id = User::where('email', $request->email)->first()->id;
        UserWallet::where('user_id', $get_user_id)->increment('balance', $request->amount) ?? null;



        return response()->json([
            'status' => true,
            'message' => "NGN $amount has been successfully added to your wallet",
        ]);

    }


    public function verify_username(request $request)
    {

        $get_user =  User::where('email', $request->email)->first() ?? null;

        if($get_user == null){

            return response()->json([
                'username' => "Not Found, Pleas try again"
            ]);

        }

        return response()->json([
            'username' => $get_user->username
        ]);



    }

}
