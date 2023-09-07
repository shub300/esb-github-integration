
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,user-scalable=0,minimal-ui">
    <meta name="description" content="ESB admin is super flexible, powerful, clean &amp; modern responsive bootstrap 4 admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="PIXINVENT">
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ config('org_details.name') }}  | @yield('title')</title>
    <link rel="apple-touch-icon" href="{{asset('public/esb_asset/images/ico/apple-icon-120.html')}}">

    <!-- Favicon -->
    @php
        $org_fav_path = config('org_details.favicon');
        $org_favicon = env('CONTENT_SERVER_PATH').$org_fav_path;
    @endphp

    @if (isset($org_fav_path))
    <link rel="shortcut icon" type="image/x-icon" href="{{ $org_favicon }}">
    @endif
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/fontawesome.min.css" integrity="sha512-OdEXQYCOldjqUEsuMKsZRj93Ht23QRlhIb8E/X0sbwZhme8eUw6g8q7AdxGJKakcBbv7+/PX0Gc2btf7Ru8cZA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;1,400;1,500;1,600" rel="stylesheet">

    <!-- BEGIN: Vendor CSS-->
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/vendors.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/charts/apexcharts.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/extensions/toastr.min.css')}}">
    <!-- END: Vendor CSS-->

    <!-- BEGIN: Theme CSS-->
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/bootstrap.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/bootstrap-extended.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/colors.min.css')}}">

    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/forms/select/select2.min.css')}}">

    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/components.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/themes/dark-layout.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/themes/bordered-layout.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/themes/semi-dark-layout.min.css')}}">

    <!-- BEGIN: Page CSS-->

    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/core/menu/menu-types/vertical-menu.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/pages/dashboard-ecommerce.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/plugins/charts/chart-apex.min.css')}}">
    {{-- <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/plugins/extensions/ext-component-toastr.min.css')}}"> --}}
    <!-- END: Page CSS-->

    <!-- BEGIN: Custom CSS-->
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/app/style.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/app/mystyle.css')}}">
    <!-- END: Custom CSS-->

<link rel="stylesheet" href="{{asset('public/plugins/toastr/toastr.min.css')}}">
<link rel="stylesheet" href="{{asset('public/plugins/pace-progress/themes/black/pace-theme-flat-top.css')}}">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.2/jquery-confirm.min.css">

<style>
    .field_error{
        display:none;
        color:red;
    }
</style>
