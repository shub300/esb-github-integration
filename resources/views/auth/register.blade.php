<!doctype html>
<html class="no-js" lang="">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>{{ config('org_details.name') }} | Register</title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    @php
    $org_fav_path = config('org_details.favicon');
    $org_favicon = env('CONTENT_SERVER_PATH').$org_fav_path;
    @endphp

    @if (isset($org_fav_path))
    <link rel="shortcut icon" type="image/x-icon" href="{{ $org_favicon }}">
    @endif

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="{{asset('public/login_assets/css/bootstrap.min.css')}}">
   
    <!-- Google Web Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&amp;display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{asset('public/login_assets/style.css')}}">
    <style>
        .with-errors {
            color: #ff0000;
            font-size: small;
            text-align: left;
        }

        .p-lr {
            padding-left: 20px;
            padding-right: 20px;
        }

        .alert-msg {
            display: block;
            font-size: 15px;
            color: #111111;
            font-weight: 500;
        }
        .labelText
        {
            float: left;
        }
    </style>
</head>

<body>
    <section class="fxt-template-animation fxt-template-layout20 loaded">
        <div class="container">
            <div class="row">
                <div class="col-xl-6 col-lg-6 col-12 fxt-none-991 fxt-bg-img">
                    <div class="disc_logo">
                        @php
                        $org_logo_path = config('org_details.logo');
                        $org_logo = env('CONTENT_SERVER_PATH').$org_logo_path;
                        @endphp

                        @if (isset($org_logo_path) && $org_logo_path)
                        {{-- <img src="{{asset('public/login_assets/img/apiworx_logo.png')}}"/> --}}
                        <img src="{{ $org_logo }}" />
                        @else
                        <h2>{{ config('org_details.name') }}</h2>
                        @endif
                    </div>
                    <div style="text-align:center;">
                        <div class="p-lr">
                            @php
                            $access_url = explode('.', config('org_details.access_url'));
                            @endphp
                            @if ($access_url[0] == 'skuvault')
                            <h3 style="font-size:18px; font-weight:500; margin-bottom:5px; text-align:left;">Bringing you more integrations, powered by Apiworx</h3>
                            <p class="pt-md-2" style="margin-bottom:15px; text-align:left; font-size:1rem; line-height:1.4; color:#424242;">Certain SkuVault integrations are powered by Apiworx. Please create an Apiworx account if your organization doesn't already have an account or log in to add and manage these integrations.&nbsp;<a href="https://www.skuvault.com/warehouse-management-system/partners/" target="_blank" rel="noopener noreferrer">Learn more</a></p>
                            <p style="text-align:justify; font-size:14px; line-height:1.4;">Current integrations offered:<br><span style="text-align:justify;"><b>Wayfair + SkuVault</b></span></p>
                            @else
                            <h3 style="text-align:center; font-weight:500; margin-bottom:5px;">Seamlessly integrate your platforms with {{ strtoupper(config('org_details.name')) }}</h3>
                            <p><br></p>
                            @endif

                            {{-- <p class="pt-md-2" style="margin-bottom:15px;">Explore &amp; set up your complete SaaS store within a few hours. Sign up with Integrato today!</p> --}}
                        </div>
                        <div>
                            <img src="{{asset('public/login_assets/img/login_bg.png')}}">
                        </div>
                    </div>
                </div>

                <div class="css-1383stg-SplitLoginPage__divider"></div>

                <div class="col-xl-6 col-lg-6 col-12 fxt-bg-color">
                    <div class="fxt-content">
                        <div class="fxt-header">
                            @if ($access_url[0] == 'skuvault')
                            <img style="max-width: 50%;" src="{{asset('public/login_assets/img/apiworx_logo.png')}}" />
                            <br><br>
                            @endif
                            <h2 style="margin-bottom: 25px;">Create Account</h2>
                        </div>
                        <div class="fxt-form">
                            @if(Session::has('fail-msg'))
                            <div class="alert alert-danger" role="alert">
                                <div class="alert-body">{{ Session::pull('fail-msg') }}</div>
                            </div>
                            @endif
                            @if(Session::has('info-msg'))
                            <div class="alert alert-primary" role="alert">
                                <div class="alert-body">{{ Session::pull('info-msg') }}</div>
                            </div>
                            @endif
                            <form method="POST" id="frmSubmit" autocomplete="off" data-toggle="validator" role="form" action="{{ url('registerEmail') }}">
                                {{ csrf_field() }}
                                <div class="form-group">
                                <label for="email" class="labelText">Enter your name</label>
                                   
                                        <input type="name" name="name" id="name" required autocomplete="off" placeholder="Please enter your name" class="form-control input-sm @error('name') is-invalid @enderror">
                                        <div class="help-block with-errors"></div>
                                 
                                </div>
                                <div class="form-group">
                                <label for="email" class="labelText">Enter your email</label>
                                  
                                        <input type="email" name="email" data-error="Your email address is invalid" id="email1" required autocomplete="off" placeholder="Enter your email address" class="form-control input-sm @error('email') is-invalid @enderror">
                                        <div class="help-block with-errors"></div>
                                  
                                </div>
                                <div class="form-group">
                                <label for="email" class="labelText">Enter your password</label>
                                  
                                <input class="form-control input-sm @error('password') is-invalid @enderror" data-error="Minimum of 12 characters" data-minlength="12" required autocomplete="off" name="password" id="password" type="password" placeholder="Enter password (Minimum 12 characters)">
                                        <div class="help-block with-errors"></div>
                          
                                </div>
                                <div class="form-group">
                                <label for="email" class="labelText">Confirm Password</label>
                                   
                                        <input class="form-control input-sm @error('password') is-invalid @enderror" data-match="#password" data-match-error="Whoops, Password and Confirm Password don't match" required autocomplete="off" name="confirm_password" type="password" placeholder="Confirm password">
                                        <div class="help-block with-errors"></div>
                                   
                                </div>
                                <!--  -->
                                <div class="form-group">
                                    <div class="fxt-transformY-50 fxt-transition-delay-1">
                                        <input type="hidden" name="platform_id" id='platform_id' value="{{$platform_id}}">
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>
                                <!--  -->
                                <div class="form-group">
                                    <div class="fxt-transformY-50 fxt-transition-delay-5">
                                        <div class="fxt-checkbox-area">
                                            <div class="checkbox">
                                                @php
                                                //use App\Http\Controllers\CommonController;
                                                //is_set = CommonController::checkTermsAndPolicies();
                                                @endphp
                                                
                                                <input id="checkbox1" type="checkbox" data-error="Please check this box to proceed" required>
                                                <label for="checkbox1" style="color:#646464 !important;">Please agree to the {{ config('org_details.name') }} <a href="{{(config('org_details.terms_url')) ? config('org_details.terms_url') : '#' }}" target="_blank">Terms and Conditions</a></label>
                                                <div class="help-block with-errors"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="fxt-transformY-50 fxt-transition-delay-6">
                                        <button type="submit" class="fxt-btn-fill btnSubmit">Register</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="fxt-footer">
                            <div class="fxt-transformY-50 fxt-transition-delay-5 disc">
                                <p style="margin:0">Already have an account? <a href="{{url('/login')}}" class="switcher-text">Login in here</a></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-12 col-lg-12 col-12 pb-3 fxt-bg-color text-center">
                    Copyright &copy; <?= date('Y') ?>, <a href="https://{{config('org_details.access_url')}}" target="_blank">{{config('org_details.name')}}</a> | All rights reserved.
                </div>
                <div class="col-xl-12 col-lg-12 col-12 pb-3 fxt-bg-color text-center">
                    @php 
                        $pipe = (config('org_details.privacy_url') && config('org_details.terms_url')) ? '|' : '';
                    @endphp
                     @if(config('org_details.privacy_url')) <a href="{{config('org_details.privacy_url')}}" target="_blank">Privacy</a>  @endif 
                     {{$pipe}}
                     @if(config('org_details.terms_url'))
                        <a href="{{config('org_details.terms_url')}}" target="_blank">Terms</a> 
                     @endif 
                </div>
            </div>
                <div class="col-xl-12 col-lg-12 col-12 mt-3 text-center">
                    <a title="An Effortless Vulnerability Scanner" href="https://intruder.io/?utm_source=badge"><img style="width:150px;height:50px" src="https://storage.googleapis.com/intruder-assets/20200528/intruder-light-badge.svg" alt="Intruder | An Effortless Vulnerability Scanner" /></a>
                </div>
        </div>
    </section>

    <!-- jquery-->
    <script src="{{asset('public/login_assets/js/jquery-3.5.0.min.js')}}"></script>
    <!-- Popper js -->
    <script src="{{asset('public/login_assets/js/popper.min.js')}}"></script>
    <!-- Bootstrap js -->
    <script src="{{asset('public/login_assets/js/bootstrap.min.js')}}"></script>
    <!-- Imagesloaded js -->
    <script src="{{asset('public/login_assets/js/imagesloaded.pkgd.min.js')}}"></script>
    <!-- Validator js -->
    <script src="{{asset('public/login_assets/js/validator.min.js')}}"></script>
    <!-- Custom Js -->
    <script src="{{asset('public/login_assets/js/main.js')}}"></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/flickity/1.0.0/flickity.pkgd.min.js'></script>
    <script src="{{asset('public/login_assets/js/testimonial_script.js')}}"></script>

    @php
        echo config('org_details.support_code');
    @endphp
    
    <script>
        $('#frmSubmit').validator().on('submit', function(e) {
            if (e.isDefaultPrevented()) {
                // handle the invalid form...
                return false;
            } else {
                // everything looks good!
                $(".btnSubmit").attr("disabled", true);
                return true;
            }
        })
    </script>
</body>

</html>