@extends('layouts.master')

@section('head-content')
@endsection

@section('title', 'Authenticate Account')
@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Authenticate Account</li>
@endsection

@push('page-style')

<style>
    .auth-wrapper {
        display: -webkit-box;
        display: -webkit-flex;
        display: -ms-flexbox;
        display: flex;
        -webkit-flex-basis: 100%;
        -ms-flex-preferred-size: 100%;
        flex-basis: 100%;
        min-height: 100vh;
        min-height: calc(var(--vh, 1vh) * 100);
        width: 100%
    }

    .auth-wrapper .auth-inner {
        width: 100%
    }

    .auth-wrapper.auth-v1 {
        -webkit-box-align: center;
        -webkit-align-items: center;
        -ms-flex-align: center;
        align-items: center;
        -webkit-box-pack: center;
        -webkit-justify-content: center;
        -ms-flex-pack: center;
        justify-content: center;
        overflow: hidden
    }

    .auth-wrapper.auth-v1 .auth-inner {
        position: relative;
        max-width: 400px
    }

    .auth-wrapper.auth-v1 .auth-inner:before {
        width: 244px;
        height: 243px;
        content: ' ';
        position: absolute;
        top: -40px;
        left: -46px;
        background-image: url('')
    }

    .auth-wrapper.auth-v1 .auth-inner:after {
        width: 272px;
        height: 272px;
        content: ' ';
        position: absolute;
        bottom: -40px;
        right: -75px;
        background-image: url('');
        z-index: -1
    }

    @media (max-width:575.98px) {

        .auth-wrapper.auth-v1 .auth-inner:after,
        .auth-wrapper.auth-v1 .auth-inner:before {
            display: none
        }
    }

    .auth-wrapper.auth-v2 {
        -webkit-box-align: start;
        -webkit-align-items: flex-start;
        -ms-flex-align: start;
        align-items: flex-start
    }

    .auth-wrapper.auth-v2 .auth-inner {
        overflow-y: auto;
        height: calc(var(--vh, 1vh) * 100)
    }

    .auth-wrapper.auth-v2 .brand-logo {
        position: absolute;
        top: 2rem;
        left: 2rem;
        margin: 0;
        z-index: 1;
        -webkit-box-pack: unset;
        -webkit-justify-content: unset;
        -ms-flex-pack: unset;
        justify-content: unset
    }

    .auth-wrapper .brand-logo {
        display: -webkit-box;
        display: -webkit-flex;
        display: -ms-flexbox;
        display: flex;
        -webkit-box-pack: center;
        -webkit-justify-content: center;
        -ms-flex-pack: center;
        justify-content: center;
        margin: 1rem 0 2rem
    }

    .auth-wrapper .brand-logo .brand-text {
        font-weight: 600
    }

    .auth-wrapper .auth-footer-btn .btn {
        padding: .6rem !important
    }

    .auth-wrapper .auth-footer-btn .btn:not(:last-child) {
        margin-right: 1rem
    }

    .auth-wrapper .auth-footer-btn .btn:focus {
        box-shadow: none
    }

    @media (min-width:1200px) {
        .auth-wrapper.auth-v2 .auth-card {
            width: 400px
        }
    }

    @media (max-width:575.98px) {
        .auth-wrapper.auth-v2 .brand-logo {
            left: 1.5rem;
            padding-left: 0
        }
    }

    .auth-wrapper .auth-bg {
        background-color: #FFF
    }

    .dark-layout .auth-wrapper .auth-bg {
        background-color: #283046
    }

    @media (max-height:625px) {
        .dark-layout .auth-wrapper .auth-inner {
            background-color: #283046
        }

        .auth-wrapper .auth-bg {
            padding-top: 3rem
        }

        .auth-wrapper .auth-inner {
            background-color: #FFF;
            padding-bottom: 1rem
        }

        .auth-wrapper.auth-v2 .brand-logo {
            position: relative;
            left: 0;
            padding-left: 1.5rem
        }
    }


    .pace .pace-progress {
        height: 4px;
    }

    .header-navbar,
    .main-search-list-defaultlist,
    .main-menu,
    .content-header-left,
    .footer,
    .header-navbar-shadow,
    .drag-target {
        display: none !important;
    }

    .app-content {
        width: 100% !important;
        margin: 0 !important;
        padding-top: 0 !important;
    }

    @media(max-width:767px) {
        html body .app-content {
            padding: 0 !important;
        }
    }
</style>

@endpush

@section('page-content')


<div class="auth-wrapper auth-v1 px-2">
    <div class="auth-inner py-2">
        <!-- Register v1 -->
        <div class="card mb-0">
            <div class="card-body">
                @if($platform=='squarespace')
                <div class="brand-logo">



                        <img class="icon" src="{{env('CONTENT_SERVER_PATH')}}{{'/public/esb_asset/brand_icons/squarespace.jpg'}}" width="30%" height="30%">



                </div>

                {{-- <h4 class="card-title mb-1 text-center">Enter Authentication Details</h4> --}}
                {{-- <p class="card-text mb-2 text-center">Make your account easy and fun!</p> --}}

                <form method="POST" action="{{route('squarespace.connect')}}"  onsubmit="return validateForm('squarespace');">
                    @csrf
                    @include('flash.flash_message')
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="account_name" name="account_name"
                            placeholder="Ex. TestSquarespaceAccount">
                        <small class="field_error">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="submit"
                                class="btn btn-primary waves-effect waves-float waves-light connect-now w-100"
                                data-text='Connect Now'>
                                <span class="spinner-border spinner-border-sm loading-icon" role="status"
                                    aria-hidden="true"></span> <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </form>
                @endif


            </div>
        </div>
        <!-- /Register v1 -->
    </div>
</div>



@endsection
@push('page-script')
<script>
    function validateForm(platform) {
    valid = true;
    $('.field_error').hide();
    if (platform == 'squarespace') {
      if (!checkvalue($('#account_name'))) {
        showErrorAndFocus($('#account_name'));
        valid = false;
      }
    }
    if (!valid) {
      return false;
    }
    return true;
  }

  function showErrorAndFocus(elem) {
    if (elem.length) {
      elem.next().show();
    }
  }
  function checkvalue(elem) {

    var input = elem.val();
    var regex = /(<([^>]+)>)/ig;
    var validatedInput = input.replace(regex, "");
    if(input != validatedInput){
        return false;
    }

    if (elem.length) {
      if (!elem.val().trim()) {
        return false;
      } else {
        return true;
      }
    }
  }
  $('body').on('change','#account_id',function(){
    $('.field_error').hide();
  });
</script>
@endpush
