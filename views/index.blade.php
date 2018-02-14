@extends('layouts.default.default')
@section('header')
    <!-- Font Awesome Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome-animation/0.0.9/font-awesome-animation.css" media="screen" title="no title" charset="utf-8">
    {{ HTML::style('css/library.css') }}
    <link href="https://fonts.googleapis.com/css?family=Titillium+Web:400,400i,600,700" rel="stylesheet">
@stop
@section('content-fluid')
<!-- This code load the widget -->
<script src="{{URL::to('/').'/js/client/library.js'}}"></script>
<script>
    var transitionTime;
    $(window).load(function(){

        $.get("{{URL::route('client.product.widgetupsell')}}",function(data,status){
            if(status =='success'){
                $('#widget').hide();
                $('#widget').html(data);
                transitionTime = parseInt($(data).filter('#time').text());
                $('.other').hide();
                $('#widget').slideDown();
                setInterval(function () {
                    if($('.current').next().hasClass('other')){
                        $('.current').removeClass('current').hide()
                            .next().show().addClass('current');
                    }
                    else{
                        $('.current').removeClass('current').hide();
                        $('.first').show().addClass('current');
                    }
                },transitionTime);
            }
            else{
                $('#widget').html(function(){ return '<h3 hidden>Code fails</h3>';});
            }
        });

    });
</script>
<div class="row">
    <div class="" id="">
        <div class="col-md-3 affix-sidebar">
            @include('layouts.default.partials.sidebar')
        </div>
    </div>
    <div class="col-md-9">
        <div class="row">
            <div class="col-xs-12">
                <h4 class="cht-grey">My Library</h4>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                @include('layouts.default.partials.my_webinars')
            </div>
        </div>

        <div class="row">
            <div class="col-sm-12">
                @if ($user->isLoyaltyEligible())
                    <div class="alert alert-dismissible alert-info">
                        <button type="button" class="close" data-dismiss="alert">×</button>
                        <strong>You are eligible for our new rewards program!</strong><br>
                        Attend the premium webinar of your choice absolutely free!  Earn points by sharing our training webinars with others, <a href="{{ URL::route('client.loyalty.index') }}">click here for more information!</a>
                    </div>
                @endif
                @if($userWithUpcoming && false)
                    <div class="row" style="border-bottom: 1px solid #eee;">
                        <div class="col-sm-12">
                            <p class="text-center">
                                <b>
                                    You will be notified when your slides and bonus<br>
                                    materials are available for <?=$userWithUpcoming->productsString()?>
                                </b>
                            </p>
                        </div>
                    </div>
                @endif
                <div class="row">
                    @if (count($currentVariations))
                        @include('pages.client.library.partials.todays-webinars')
                    @endif
                    @include('pages.client.library.partials.product-cards', ['products' => $user->products, 'productsWithVariation' => $productsWithVariation])
                    <div class="col-md-4 col-sm-6">
                        <div id="widget"></div>
                    </div>
                </div>
                @if (!$user->products->count())
                    @if ($user->hasActiveMembership())
                        <p class="lead text-center">
                            You haven't added any items to your library yet!<br>
                            Please feel free to browse and add items specific to your membership level!
                        </p>
                    @else
                        <p class="lead text-center">
                            You do not have any items in your library yet!<br>
                            To get started, take a look at some of our <strong><a href="<?=URL::route('client.products.free-webinars')?>">free training courses</a></strong>
                            you can add to your library!
                        </p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@stop
