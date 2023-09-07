@extends('layouts.master')

@section('head-content')

    <style>

    </style>

@endsection

@section('title', 'Connection Settings for')
@section('connection_title', $con_data->p1_name.' + '.$con_data->p2_name)
{{-- @section('connection_title', 'Connection Settings for '.$con_data->p1_name.' + '.$con_data->p2_name) --}}

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Connection Settings</li>
@endsection

@push('page-style')
<!-- select2 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
{{-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" integrity="sha256-FdatTf20PQr/rWg+cAKfl6j4/IY3oohFAJ7gVC3M34E=" crossorigin="anonymous"> --}}
<link rel="stylesheet" type="text/css" href="{{ asset('public/select2/css/select2-bootstrap4.css') }}">

<style>
    .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
        text-align: left !important;
    }

</style>
<!-- end select2-->
<!-- BEGIN: plat picker CSS-->
<link rel="stylesheet" type="text/css" href="{{ asset('public/flatpicker/form-flat-pickr.min.css') }}">
<!-- END: Page CSS-->

<!-- BEGIN: Page Vendor JS-->
<link rel="stylesheet" type="text/css"
    href="{{ asset('public/esb_asset/vendors/css/tables/datatable/dataTables.bootstrap5.min.css') }}">
<link rel="stylesheet" type="text/css"
    href="{{ asset('public/esb_asset/vendors/css/tables/datatable/responsive.bootstrap4.min.css') }}">
<link rel="stylesheet" type="text/css"
    href="{{ asset('public/esb_asset/vendors/css/pickers/flatpickr/flatpickr.min.css') }}">
<!-- END: Page Vendor JS-->


 <!-- BEGIN: file upload CSS-->
<!-- <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/vendors.min.css')}}"> -->
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/file-uploaders/dropzone.min.css')}}">
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/plugins/forms/form-file-uploader.min.css')}}">
<!-- END: Vendor CSS-->



<style>
     @media only screen and (max-width: 768px) {
        div.col-md-6{
            padding-left: 0px !important;
            padding-right:0px !important;
        }
    }
    .select2-container
    {
        width : auto !important;
    }
      .defaultMappingLabel
    {
        text-align: center;
        padding: 8px;
        background-color: #F3F2F7;
        border-radius:.357rem;
        border: 1px solid #D8D6DE;
    }
	.col-md-5{
		margin-bottom:15px !important;
	}
	.accoBtn {
		width: 25px;
		height: 25px;
		color: #6e6b7b;
		cursor: pointer;
		transition: transform .5s;
	}

    .setup-icon-box {
        padding-right: 10px;
    }

    .dhide {
        visibility: hidden;
    }




    .app-icon .icon {
        padding: 10px;
        border: 1px dashed #aba9a9;
        border: 1px dashed #7367f0;
        border-radius: 10px;

        width: 100%;
        max-width: 120px;
        background: #fff;
    }

    .my-app-title {
        margin-bottom: 15px;
    }

    .connect-app {
        position: absolute;
        width: 50%;
        right: 25%;
        top: 50%;
        border-top: 1px dashed #7367f0;
    }

    .p-relative {
        position: relative;
    }

    .myApp-item .app-icon .icon {}

    .myApp-item {
        background: linear-gradient(45deg, #dcdaff, #c2bfef);
        overflow: hidden;
    }

    .myApp-item .card-body>span {
        /*content:'';
   width:400px;
   height:400px;
   left:-200px;
   bottom:-200px;
   border-radius:50%;
   position:absolute;
             background: rgb(94 80 238 / 18%);
   width: 180px;*/

        /*transform: skewX(20deg) translateX(50px);
   position: absolute;
   right: 0;
   height: inherit;
   background-image: linear-gradient(to bottom, #ff6767, #ff4545);
   box-shadow: 0 0 10px 0px rgb(0 0 0 / 50%);*/
    }

    .work-item .media>.avatar {
        border: 1px solid #7367f0;
        border-radius: 50%;
    }

    .work-item-card:hover {
        box-shadow: 0 4px 24px 0 rgb(94 80 238 / 50%);
    }

    .work-item .avatar .avatar-content .avatar-icon {
        width: 38px;
        height: 38px;
    }

    .avatar .avatar-content {
        width: 38px;
        height: 38px;
    }

    .work-item .avatar-content h2 {
        color: #7367f0;
    }

    .mw-120 {
        min-width: 165px;
    }

    .v-line:after {
        content: "";
        position: absolute;
        width: 2px;
        height: 20px;
        background: #7367f0;
        top: -17px;
        z-index: -1;
        margin-left: 22px;
    }


    .card.work-item-card {
        border-top-left-radius: 40px;
        border-bottom-left-radius: 40px;
    }

    /* .or{
   width: 85px;
    margin-top: 10px;
  }
  */
    .action {
        width: 18%;
    }
    .brand-logo-file{
        max-width: 90%;
        padding-left: 40px;
        padding-top: 80px;
        opacity: 0.2;
        filter: blur(1px);
    }
    .err_mgs{
        color: red;
        display: none;
        font-size: small;
    }

    .dropzone {
        min-height:225px !important;
    }
    .dropzone .dz-message:before {
        top: 11rem !important;
    }
    .dz-message {
        margin-top: -30px !important;
    }
    .dz-progress {
        display: none;
    }
    .dz-image img
    {
        max-height:120px !important;
    }
    .source_disconnect {
        float: right;
        margin-right: 10px;
        margin-top:5px;
        border-radius: 10px;
    }
    .destination_disconnect {
        float: right;
        margin-right: 10px;
        margin-top:5px;
        border-radius: 10px;
    }



</style>
@endpush

@section('page-content')


<!-- Workflow Section -->
<section id="dashboard-ecommerce" data-id="{{ $id }}" class="connectionId" style="margin-top: -40px !important;">

    <div class="col-md-12 my-1">
        <div class="group-area" style="text-align: center;">
            <h4>{{ $con_data->flow_name }}</h4>
        </div>
    </div>

    <div class="row match-height">
        <!-- workflow Card -->
        <div class="col-xl-12 col-md-12 col-12">

            <div class="card work-item-card">
                <div class="card-body work-item">
                    <div class="row">
                        <div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
                            <div class="media">
                                <div class="avatar bg-light-primary mr-2">
                                    <div class="avatar-content">
                                        <h2 class='mb-0'>
                                        <img class="round" src="{{env('CONTENT_SERVER_PATH').($con_data->p1_image)}}" height="40" width="40">
                                        </h2>
                                    </div>
                                </div>

                                <div class="media-body my-auto">
                                    <input type="hidden" id="source_platform_id" data-name="{{ $con_data->p1_name }}"
                                        name="source_platform_id" value="{{ $con_data->p1_id }}" />
                                    <input type="hidden" id="source_connected" value="{{ $ac_connected_source }}" />
                                    <h4 class="font-weight-bolder mb-0 dark-text">Connect {{ $con_data->p1_name }}
                                        <input type="hidden" id="p1_rowid" value="{{ $con_data->p1_rowid }}">
                                        <span class="source_disconnect"></span>
                                    </h4>
                                    <p class="card-text font-small-3 mb-0"> Log in to connect to your {{ $con_data->p1_name }} account</p>

                                </div>

                                <div class="action">

                                    @if (!$sc || $workflow_status == 'draft')
                                        <select name="source_platform"
                                            class="form-control mr-2 select2-icons source_platform"
                                            id="source_platform">
                                            <option value="">Select Account</option>
                                            @if ($facc_source['data'])
                                                @foreach ($facc_source['data'] as $desp)
                                                <!-- data-icon="{{ $desp->platform_image }}" -->
                                                    <option
                                                        value="{{ $desp->id }}"
                                                        {{ $sc == $desp->id ? 'selected' : '' }}>
                                                        {{ $desp->account_name }}</option>
                                                @endforeach
                                                <option value="" disabled="disabled">---------</option>
                                                <option
                                                    data-src="{{ $con_data->p1_auth_endpoint ? url($con_data->p1_auth_endpoint) : '' }}"
                                                    data-auth_type="{{ $con_data->p1_auth_type ? $con_data->p1_auth_type : '' }}"
                                                    value="add-new" class="p1_auth_endpoint">Add New {{ $con_data->p1_name }} Account</option>
                                            @endif
                                        </select>


                                    @else
                                        <select name="source_platform"
                                            class="form-control mr-2 select2-icons source_platform" id="source_platform"
                                            disabled>

                                            @if ($facc_source['data'])
                                                @foreach ($facc_source['data'] as $desp)
                                                <!-- data-icon="{{ $desp->platform_image }}" -->
                                                    <option
                                                        value="{{ $desp->id }}"
                                                        {{ $sc == $desp->id ? 'selected' : '' }}>
                                                        {{ $desp->account_name }}</option>
                                                @endforeach

                                            @endif
                                        </select>
                                    @endif


                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row" style="display: none;" id="p1_conn_section">
                        <div class="offset-1 col-xl-10 col-sm-10 col-10 mb-2 mb-xl-0" id="p1_connection_form"></div>
                        <div class="col-xl-3 col-sm-3 col-3 mb-2 mb-xl-0"></div>
                    </div>

                </div>
            </div>

            <div class="card work-item-card ">
                <div class="card-body work-item v-line">
                    <div class="row">
                        <div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
                            <div class="media">
                                <div class="avatar bg-light-primary mr-2">
                                    <div class="avatar-content">
                                        <h2 class='mb-0'>
                                        <img class="round" src="{{env('CONTENT_SERVER_PATH').($con_data->p2_image)}}"  height="40" width="40">
                                        </h2>
                                    </div>
                                </div>

                                <div class="media-body my-auto">
                                    <input type="hidden" id="destination_platform_id" name="destination_platform_id"
                                        data-name="{{ $con_data->p2_name }}" value="{{ $con_data->p2_id }}" />
                                    <input type="hidden" id="destination_connected"
                                        value="{{ $ac_connected_destination }}" />
                                    <h4 class="font-weight-bolder mb-0 dark-text">Connect {{ $con_data->p2_name }}
                                        <input type="hidden" id="p2_rowid" value="{{ $con_data->p2_rowid }}">
                                        <span class="destination_disconnect"></span>
                                    </h4>
                                    <p class="card-text font-small-3 mb-0">Log in to connect to your {{ $con_data->p2_name }} account
                                    </p>
                                </div>

                                <div class="action">

                                    @if (!$dc || $workflow_status == 'draft')
                                        <select name="destination_platform"
                                            class="form-control mr-2 select2-icons destination_platform"
                                            id="destination_platform">
                                            <option value="">Select Account</option>
                                            @if ($facc_destination['data'])
                                                @foreach ($facc_destination['data'] as $desp)
                                                <!-- data-icon="{{ $desp->platform_image }}" -->
                                                    <option
                                                        value="{{ $desp->id }}"
                                                        {{ $dc == $desp->id ? 'selected' : '' }}>
                                                        {{ $desp->account_name }}</option>
                                                @endforeach
                                                <option value="" disabled="disabled">---------</option>
                                                <option
                                                    data-src="{{ $con_data->p2_auth_endpoint ? url($con_data->p2_auth_endpoint) : '' }}"
                                                    data-auth_type="{{ $con_data->p2_auth_type ? $con_data->p2_auth_type : '' }}"
                                                    value="add-new" class="p2_auth_endpoint">Add New {{ $con_data->p2_name }} Account</option>
                                            @endif
                                        </select>
                                    @else
                                        <select name="destination_platform"
                                            class="form-control mr-2 select2-icons destination_platform"
                                            id="destination_platform" disabled>

                                            @if ($facc_destination['data'])
                                                @foreach ($facc_destination['data'] as $desp)
                                                <!-- data-icon="{{ $desp->platform_image }}" -->
                                                    <option
                                                        value="{{ $desp->id }}"
                                                        {{ $dc == $desp->id ? 'selected' : '' }}>
                                                        {{ $desp->account_name }}</option>
                                                @endforeach

                                            @endif
                                        </select>
                                    @endif


                                </div>
                            </div>
                        </div>
                    </div>

					<div class="row" style="display: none;" id="p2_conn_section">
                        <div class="offset-1 col-xl-10 col-sm-10 col-10 mb-2 mb-xl-0" id="p2_connection_form"></div>
                        <div class="col-xl-3 col-sm-3 col-3 mb-2 mb-xl-0"></div>
                    </div>
                </div>
            </div>

            <input type="hidden" value="{{ $id }}" id="input_IntegPlateformId">
            <input type="hidden" value="{{ url('/') }}" id="AjaxCallUrl">
            <input type="hidden" value="{{ env('CONTENT_SERVER_PATH') }}" id="contentServerPath">


            <div class="card work-item-card">
                <div class="card-body work-item v-line">
                    <div class="row">
                        <div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
                            <div class="media">
                                <div class="avatar bg-light-primary mr-2">
                                    <div class="avatar-content">
                                        <h2 class='mb-0'><i class="fa fa-cogs" aria-hidden="true"></i></h2>
                                    </div>
                                </div>

                                <div class="media-body my-auto">
                                    <h4 class="font-weight-bolder mb-0 dark-text">Configure Settings</h4>
                                    <p class="card-text font-small-3 mb-0">Configure your {{$con_data->p1_name}} + {{$con_data->p2_name}} integration settings.</p>
                                    {{-- <p class="card-text font-small-3 mb-0">{{$con_data->p1_name}} + {{$con_data->p2_name}}</p> --}}
                                </div>

                                <div class="action" style="width: 25%;float:right;justify-content: right;display: flex;">
                                <button type="button" class="btn btn-outline-primary waves-effect waves-float waves-light btnResetMapping2 primary-btn-style" style="padding: 10px !important;font-size:15px" onclick="openMapping(2,1)">Click To Proceed</button>
                                <i class="fa fa-question-circle" aria-hidden="true" style="font-size:22px;margin:10px;cursor: pointer;" data-toggle="tooltip" data-placement="top" title="This will fetch or refresh the most up to date options for the fields below. If you don’t see an option for one of the fields below, click on this button to fetch the latest data. For example, this will show you the most updated list of warehouses in the system."></i>
                                </div>

                            </div>


                            <br>
                            <div class="row">
                                <div class="col-md-12" id="MappingDataContainer">
                                    <!--All mapping selector will be apear here -->
                                </div>
                                <div class="col-md-12">
                                    <div class="row text-center mx-0 mb-1 justify-content-center"
                                        style="text-align: center;align-items:center;">
                                        <button type="button" class="btn btn-success btn-md mappingSaveBtn"
                                            style="display: none" onClick="storeMapping(2)">Save Mapping</button>
                                    </div>
                                </div>
                            </div>


                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!--/ Statistics Card -->
    </div>


</section>
<!-- Dashboard Ecommerce ends -->

@endsection

@push('page-script')

{{-- We have to maintain each page separate js file in public/js/page dir --}}
<script src="{{ asset('public/js/pages/connection_setting/connection_settings_' .app('App\Utility\JsVersionDefination')::CONNECTION_SETTING) }}.js"></script>
<script src="{{ asset('public/js/integration_flow/integration_flow_' .app('App\Utility\JsVersionDefination')::INTEGRATIONFLOW) }}.js"></script>
<script src="{{ asset('public/js/pages/mapping_validation/mapping_validation_' .app('App\Utility\JsVersionDefination')::MAPPING_VALIDATION) }}.js"></script>

<!-- select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"
integrity="sha256-AFAYEOkzB6iIKnTYZOdUf9FFje6lOTYdwRJKwTN5mks=" crossorigin="anonymous"></script>
<script src="{{ asset('public/select2/js/script.js') }}"></script>
<!--end -->
<!--start flat picker -->
<script src="{{ asset('public/flatpicker/flatpickr.min.js') }}"></script>
<!-- end-->
<!-- BEGIN: Page JS-->
{{-- <script src="{{asset('public/flatpicker/form-pickers.min.js')}}"></script> --}}
<!-- END: Page JS-->

<!--file upload js -->
<script src="{{asset('public/esb_asset/vendors/js/extensions/dropzone.min.js')}}"></script>
<!-- <script src="{{asset('public/esb_asset/js/scripts/forms/form-file-uploader.min.js')}}"></script> -->
<!-- end file upload-->

<script src="{{asset('public/js/pages/auth_netsuite.js?v=1.2')}}"></script>
<script src="{{asset('public/js/pages/auth_magento.js?v=1.2')}}"></script>

<script>
    //check account selected to show hide disconnect button
    $( document ).ready(function() {
        //check source side
        let source_connected = $("#source_connected").val();
        let p1_rowid = $("#p1_rowid").val();
        handleDisconnectButton(source_connected,p1_rowid,'source');
        //check destination side
        let destination_connected = $("#destination_connected").val();
        let p2_rowid = $("#p2_rowid").val();
        handleDisconnectButton(destination_connected,p2_rowid,'destination');
    });

    // get connected account detail on change...source
    $(document).on('change', '#source_platform', function() {
        let p1_rowid = $("#p1_rowid").val();
        let sourceAccId = $(this).val();
        if(sourceAccId && sourceAccId !="add-new"){
            handleDisconnectButton(1,p1_rowid,'source');
        }
    });

    // get connected account detail on change...destination
    $(document).on('change', '#destination_platform', function() {
        let p2_rowid = $("#p2_rowid").val();
        let destinationAccId = $(this).val();
        if(destinationAccId && destinationAccId !="add-new"){
            handleDisconnectButton(1,p2_rowid,'destination');
        }
    });

    function handleDisconnectButton(connectStatus,RowId,dynClassId){
        if(connectStatus > 0){
            let AccId = $("#"+dynClassId+"_platform").val();
            let AccName = $( "#"+dynClassId+"_platform option:selected" ).text().trim();
            let dynDisconBtn = `<button type="button" class="btn btn-primary btn-sm disconnect" data-id="${AccId}" data-platformid="${RowId}" data-platform_name="${AccName}"><i class="fa fa-chain-broken" aria-hidden="true"></i> Disconnect</button>`;

            $("."+dynClassId+"_disconnect").html(dynDisconBtn);
            $("."+dynClassId+"_disconnect").show();
        } else {
            let dynDisconBtn = '';
            $("."+dynClassId+"_disconnect").html(dynDisconBtn);
            $("."+dynClassId+"_disconnect").hide();
        }
    }

    $(document).on('click', '.disconnect', function() {
        var id = $(this).attr("data-id");
		var platform_id = $(this).attr("data-platformid");
        var pan = $(this).attr("data-platform_name");
		let platform_account_name = pan.charAt(0).toUpperCase() + pan.slice(1);
        var userIntegId = $('#input_IntegPlateformId').val();
        var csrf_token = $('meta[name="csrf-token"]').attr('content');
        var fixedUrl = $("#AjaxCallUrl").val();
		disconnect_confirmation("Confirm!","Are you sure that you want to disconnect your <b>" + platform_account_name + "</b> account ? <br><br> <b>Caution:</b> Disconnecting this account will make your all integrations inactive which uses the same account ",userIntegId, platform_id, id, csrf_token);
    })
</script>
@endpush
