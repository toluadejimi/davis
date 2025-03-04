@extends($theme.'layouts.app')
@section('title','405')


@section('content')
    <section class="not-found">
        <div class="container h-100">
            <div class="row h-100 justify-content-center">
                <div class="col-md-6">
                    <div class="text-box text-center">
                        <h1>@lang('Opps!')</h1>
                        <p>@lang("Method not allowed")</p>
                        <a class="btn back_404" href="{{url('/')}}">@lang('Back To Home')</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- /ERROR -->
@endsection
