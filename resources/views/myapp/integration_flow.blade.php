@extends('layouts.master')

@section('head-content')

<!-- BEGIN: plat picker CSS-->
<link rel="stylesheet" type="text/css" href="{{asset('public/flatpicker/form-flat-pickr.min.css')}}">
<!-- END: Page CSS-->

<!-- BEGIN: Page Vendor JS-->
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/tables/datatable/dataTables.bootstrap5.min.css')}}">
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/tables/datatable/responsive.bootstrap4.min.css')}}">
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/pickers/flatpickr/flatpickr.min.css')}}">
<!-- END: Page Vendor JS-->

<!-- daterange picker -->
<link rel="stylesheet" href="{{asset('public/plugins/daterangepicker/daterangepicker.css')}}">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<!-- select2 -->
<!-- aded for timeline-->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />


<link rel="stylesheet" type="text/css" href="{{asset('public/css/select2@4.0.13/dist/css/select2.min.css')}}">
<!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" integrity="sha256-FdatTf20PQr/rWg+cAKfl6j4/IY3oohFAJ7gVC3M34E=" crossorigin="anonymous"> -->
<link rel="stylesheet" type="text/css" href="{{asset('public/select2/css/select2-bootstrap4.css')}}">

<!-- BEGIN: file upload CSS-->
<!-- <link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/vendors.min.css')}}"> -->
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/vendors/css/file-uploaders/dropzone.min.css')}}">
<link rel="stylesheet" type="text/css" href="{{asset('public/esb_asset/css/plugins/forms/form-file-uploader.min.css')}}">
<!-- END: Vendor CSS-->

<!-- <link rel="stylesheet" type="text/css" href="{{asset('public/css/adminlte.min.css')}}"> -->

@endsection

@section('title', 'Integration Flows')

@section('side-bar')
@include('layouts.menu-bar')
@include('layouts.mapping-sidebar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Integration Flows</li>
@endsection

@push('page-style')
<!--css to hide Export and other toolbar buttons css-->
<!-- css modified-->
<style>
    .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
	text-align: left !important;
	}
	
    .dataTables_length
    {
	display: flex;
	flex-wrap: wrap;
	justify-content:space-between;
    }
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
    .select2-selection__rendered
    {
	padding:3px !important;
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
	
	.dataTables_length select.form-select {
	border: 1px solid #ccc;
	padding: 5px !important;
	border-radius: 4px;
	}
	
	.dataTables_info {
	margin-bottom: 10px;
	}
	
	.dt-action-buttons.text-right,
	.dataTables_wrapper .card-header {
	display: none;
	}
	
	
	table.dataTable>thead .sorting:before,
	table.dataTable>thead .sorting_asc:before,
	table.dataTable>thead .sorting_desc:before,
	table.dataTable>thead .sorting_asc_disabled:before,
	table.dataTable>thead .sorting_desc_disabled:before {
	right: .5em !important;
	content: "";
	}
	
	table.dataTable>thead .sorting:after,
	table.dataTable>thead .sorting_asc:after,
	table.dataTable>thead .sorting_desc:after,
	table.dataTable>thead .sorting_asc_disabled:after,
	table.dataTable>thead .sorting_desc_disabled:after {
	content: "";
	}
    .failed-tooltip{
	font-size: 17px;
	position: absolute;
	margin-top:2px;
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
    .bullhornTooltipText 
    {
	font-size:13px;
	margin-top:-18px;
	margin-bottom:15px;
	/* color: #030304; */
    }
    .form-control:disabled, .form-control[readonly] {
    background-color: #F3F2F7 !important;
    opacity: 1 !important;
    }
    .dataTables_processing {
	color:#2C6FA8!important;
	font-size:20px;
	-webkit-box-shadow: 0 0 10px #fff;
	box-shadow: 0 0 10px #fff;
	font-weight:bold;
    }
    .badge {
	font-size: 90% !important;
	font-weight: bold !important;
    }
	
    .connect_pltIcon {
	padding : 2px;
	border: 1px solid #2C6FA8;
	border-radius: 2.5rem;
    }
	
     /* Mapping Responsive start */
     @media (max-width: 768px) {

    .sorder_payment-3_mappingFieldWrapper .row,
    .product_pricelist-27_mappingFieldWrapper .row,
    .sales_order-3_Clone1 .row,
    .sorder_taxcode-3_Clone1 .row,
    .sorder_shipping_method-3_mappingFieldWrapper .row {
        display: contents !important;
        margin-top: 15px !important;
        margin-left: 15px !important;
    }

    .sales_order-3_Clone1 .col-xl-12,
    .sorder_taxcode-3_mappingFieldWrapper .col-md-5 {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }

    .sorder_payment-3_mappingFieldWrapper,
    .product_pricelist-27_mappingFieldWrapper,
    .sales_order-3_Clone1,
    .sorder_taxcode-3_Clone1,
    .sorder_shipping_method-3_mappingFieldWrapper,
    .sorder_taxcode-3_D_MainItem,
    .sales_order-3_mappingFieldWrapper {
        background-color: #F3F2F7;
        padding: 10px;
        border-radius: 4px;
    }

    .sorder_taxcode-3_D_MainItem {
        padding-left: 25px !important;
        padding-right: 25px !important;
    }
    }

    @media (min-width: 768px) {
    fieldset .col-md-10 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
    }


    .timeline{margin:0 0 45px;padding:0;position:relative}.timeline::before{border-radius:.25rem;background-color:#dee2e6;bottom:0;content:"";left:31px;margin:0;position:absolute;top:0;width:4px}.timeline>div{margin-bottom:15px;margin-right:10px;position:relative}.timeline>div::after,.timeline>div::before{content:"";display:table}.timeline>div>.timeline-item{box-shadow:0 0 1px rgba(0,0,0,.125),0 1px 3px rgba(0,0,0,.2);border-radius:.25rem;background-color:#fff;color:#495057;margin-left:60px;margin-right:15px;margin-top:0;padding:0;position:relative}.timeline>div>.timeline-item>.time{color:#999;float:right;font-size:12px;padding:10px}.timeline>div>.timeline-item>.timeline-header{border-bottom:1px solid rgba(0,0,0,.125);color:#495057;font-size:16px;line-height:1.1;margin:0;padding:10px}.timeline>div>.timeline-item>.timeline-header>a{font-weight:600}.timeline>div>.timeline-item>.timeline-body,.timeline>div>.timeline-item>.timeline-footer{padding:10px}.timeline>div>.timeline-item>.timeline-body>img{margin:10px}.timeline>div>.timeline-item>.timeline-body ol,.timeline>div>.timeline-item>.timeline-body ul,.timeline>div>.timeline-item>.timeline-body>dl{margin:0}.timeline>div>.timeline-item>.timeline-footer>a{color:#fff}.timeline>div>.fa,.timeline>div>.fab,.timeline>div>.fad,.timeline>div>.fal,.timeline>div>.far,.timeline>div>.fas,.timeline>div>.ion,.timeline>div>.svg-inline--fa{background-color:#adb5bd;border-radius:50%;font-size:16px;height:30px;left:18px;line-height:30px;position:absolute;text-align:center;top:0;width:30px}.timeline>div>.svg-inline--fa{padding:7px}.timeline>.time-label>span{border-radius:4px;background-color:#fff;display:inline-block;font-weight:600;padding:5px}.timeline-inverse>div>.timeline-item{box-shadow:none;background-color:#f8f9fa;border:1px solid #dee2e6}.timeline-inverse>div>.timeline-item>.timeline-header{border-bottom-color:#dee2e6}.dark-mode .timeline::before{background-color:#6c757d}.dark-mode .timeline>div>.timeline-item{background-color:#343a40;color:#fff;border-color:#6c757d}.dark-mode .timeline>div>.timeline-item>.timeline-header{color:#ced4da;border-color:#6c757d}.dark-mode .timeline>div>.timeline-item>.time{color:#ced4da}

    .bg-red{background-color:#dc3545!important}
    .bg-green{background-color:#28a745!important}

    .close { padding: 0.2rem 0.62rem !important; margin:0px !important }


/* Mapping Responsive end*/
</style>

@endpush

@section('page-content')
<input type="hidden" value="{{$userIntegId}}" id="input_IntegPlateformId">
<input type="hidden" value="{{$sourcePltId}}" id="sourcePlatformId">
<input type="hidden" value="{{$destPltId}}" id="destPlatformId">
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">
<input type="hidden" value="datatables-connections" id="activeTabData">

<div class="content-body" style="margin-top:-30px !important">
	<section id="basic-tabs-components">
		<div class="row match-height">
			<!-- Basic Tabs starts -->
			<div class="col-xl-12 col-lg-12">
				<div class="card">
					{{-- <div class="card-header">
						<h5 class="card-title">Integration Flows <img src="{{ asset('public/esb_asset/icons/info.svg') }}" alt="icon"></h5>
					</div> --}}
                    <div class="card-body">
                        <!-- Alert Box -->
                        <div class="demo-spacing-0" id="alertBox"></div><br>
                        <!--// Alert Box [end] -->

                        <!-- alert for reauth-->
                        <div class="alert alert-warning alert-dismissible fade show" id="reauthAlert" role="alert" style="padding:10px; display:none">
                        <p id="reauthAlertMsg"></p><button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        </div>
						
                        <ul class="nav nav-tabs" role="tablist">
                            @if ($module_rights['view_integrations'] == 1)
                            <li class="nav-item">
                                <a class="nav-link active" id="flows-tab" data-bs-toggle="tab" href="#flows" aria-controls="flows" role="tab" aria-selected="true">Flows</a>
							</li>
                            @endif
							
                            @if ($module_rights['view_integrations'] == 1)
                            <li class="nav-item">
                                <a class="nav-link" id="connections-tab" data-bs-toggle="tab" href="#connections" aria-controls="connnections" role="tab" aria-selected="false">Connections / Configuration</a>
							</li>
                            @endif
							
                            @if ($module_rights['view_logs'] == 1)
                            <li class="nav-item">
                                <a class="nav-link" id="audit_log-tab" data-bs-toggle="tab" href="#audit_log" aria-controls="audit_log" role="tab" aria-selected="false">Logs</a>
							</li>
                            @endif
						</ul>
						
                        <div class="tab-content">
                            <input type="hidden" value="" id="TabClickStatus">
                            @if ($module_rights['view_integrations'] == 1)
                            <div class="tab-pane active" id="flows" aria-labelledby="flows-tab" role="tabpanel">
                                <div class="table-responsive">
                                    <div id="transactionalFlow1">
                                        
                                    </div>

                                    <div class="mt-2" id="transactionalFlow2">
                                        
                                    </div>
								</div>
							</div>
                            @endif
							
                            @if ($module_rights['view_integrations'] == 1)
                            <div class="tab-pane" id="connections" aria-labelledby="connections-tab" role="tabpanel">
                                <div class="table-responsive">
									<table class="table">
										<thead>
											<tr>
												<th scope="col"></th>
												<th scope="col">Name</th>
												<th scope="col">Status</th>
												<th scope="col">Type</th>
												<th scope="col">Last Update</th>
												<th scope="col">Action</th>
											</tr>
										</thead>
										<tbody id="connectionDataSection">
											
										</tbody>
									</table>
								</div>
                                <hr>
                                <br>
								
								<div class="row">
                                    <div class="col-md-12 col-xs-6 text-center">
                                        <h3>Platform Mapping</h3>
									</div>
								</div>
                                <div class="row">
                                    <div class="col-md-3 offset-md-9 col-xs-6 text-center">
										@if ($module_rights['modify_integrations'] == 1)
                                        <button type="button" class="btn btn-outline-primary waves-effect waves-float waves-light secondary-btn-style" style="padding: 9px !important;"
                                        onclick="openMapping(3)">Fetch Latest Data</button> 
                                        <i class="fa fa-question-circle" aria-hidden="true" style="font-size:22px;margin:10px;cursor: pointer;" data-toggle="tooltip" data-placement="top" title="This will fetch or refresh the most up to date options for the fields below. If you don’t see an option for one of the fields below, click on this button to fetch the latest data. For example, this will show you the most updated list of warehouses in the system."></i>
										@endif
									</div>
								</div>
                                <br>
								
                                <div class="row">
                                    <div class="col-md-12" id="MappingDataContainer">
                                        <!--All mapping selector will be apear here -->
									</div>
									
                                    <div class="col-md-12">
                                        <div class="row text-center mx-0 mb-1 justify-content-center" style="text-align: center;align-items:center;">
                                            @if ($module_rights['modify_integrations'] == 1)
                                            <button type="button" class="btn btn-success btn-md mappingSaveBtn primary-btn-style" style="display: none" onClick="storeMapping()">Save Mapping</button>
                                            @endif
										</div>
									</div>
								</div>
							</div>
                            @endif
							
                            @if ($module_rights['view_logs'] == 1)
                            <div class="tab-pane " id="audit_log" aria-labelledby="audit_log-tab" role="tabpanel">
                                <!-- table audit Log -->
                                <section id="basic-datatable2">
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="card">
                                                <div class="dataTables_length">
                                                    <input type="hidden" id="arrayIntegEvents" value="{{ $integration_events }}">
                                                    <div class="row" style="display: flex;width: 103% !important;margin-left:-15px;">
														
                                                        <div class="col-sm-12 col-xs-12 col-md-3">
                                                            <label>Log Type</label>
                                                            <select id="log_event" style="margin-bottom: 4px;" class="custom-select custom-select-sm form-control form-control-sm">
                                                                {{-- <option value="">-- Select Event --</option> --}}
                                                                @foreach ($integration_events as $intg)
                                                                <option value="{{ $intg->event }}" data-uwfrId="{{$intg->uwfId}}" data-sourceplt="{{$intg->sourcePlt}}" data-destplt="{{$intg->destPlt}}" data-sourcepltId="{{$intg->sourcePltId}}" data-destpltId="{{$intg->destPltId}}">{{ $intg->event_description }}</option>
                                                                @endforeach
															</select>
														</div>
                                                        <div class="col-sm-12 col-xs-12 col-md-2">
                                                            <label class="ftr-opt-lbl">Status</label>
                                                            <select id="status" style="margin-bottom: 4px;" class="custom-select custom-filter  custom-select-sm form-control form-control-sm">
                                                                <option value="">--Select Status--</option>
                                                                <option value="Pending">Pending</option>
                                                                <option value="Synced">Synced</option>
                                                                <option value="Failed">Failed</option>
                                                                <option value="Partial">Partial</option>
                                                                <option value="Processing">Processing</option>
                                                                <option value="Ignore">Ignore</option>
															</select>
														</div>
                                                        <div class="col-sm-12 col-xs-12 col-md-4">
                                                            <label class="ftr-opt-lbl">Filter By</label>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <span class="input-group-text">
                                                                        <i class="fa fa-calendar-alt"></i>
																	</span>
																</div>
                                                                <select id="FilterByDate" class="custom-select custom-filter  custom-select-sm form-control form-control-sm" onChange="CallDateFilter(this)">
                                                                    <option value="last_run">Updated At</option>
                                                                    <option value="synced_at">Synced At</option>
																</select>
                                                                <input type="text" id="date" class="form-control float-right custom-select custom-select-sm form-control form-control-sm" placeholder="Select Filter Date">
															</div>
														</div>
                                                        <div class="col-sm-12 col-xs-12 col-md-1" style="margin-top: 25px;">
                                                            <button class="btn btn-primary btn-sm" id="btnReload">
                                                                {{-- <i class="fa fa-refresh"></i>  --}}
															Reset</button>
														</div>
                                                        @if ($module_rights['modify_integrations'] == 1)
                                                        <div class="col-sm-12 col-xs-12 col-md-2 sectionResyncAll" style="margin-top: 25px;">
                                                            <button class="btn btn-warning btn-sm col-sm-12 col-xs-12" id="btn-resync-all" data-toggle="tooltip" data-placement="top" title="You click on this to re-try all your Failed status data to sync" ><i class="fa fa-refresh"></i> Resync Failed</button>
														</div>
                                                        @endif
														
													</div>
												</div>
                                                <div class="table-responsive">
                                                    <table class="datatables-auditLogs table">
                                                        <thead>
                                                            <tr>
                                                                <th>Integration Platform</th>
                                                                <th>Source Reference #</th>
                                                                <th>Destination Reference #</th>
                                                                <th>Last Synced At</th>
                                                                <th>Type</th>
                                                                <th>Status</th>
                                                                <th>Updated At</th>
                                                                <th>Action</th>
															</tr>
														</thead>
													</table>
												</div>
											</div>
										</div>
									</div>
								</section>
							</div>
                            @endif
						</div>
					</div>
				</div>
			</div>
		</div>
        <!--  -->
        <div class="modal fade" id="disconnect_modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel1">
            <div class="modal-dialog" role="document">
                <form action="javascript:void(0)" id="disconnect_form">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header d-flex align-items-center">
                            <h4 class="modal-title" id="exampleModalLabel1">Disconnect Account</h4>
                            <button type="button" class="close ml-auto" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
						</div>
                        <input type="hidden" id="platform_id" name="platform_id">
                        <input type="hidden" id="platform_account_id" name="platform_account_id">
                        <div class="modal-body" id='disconnect_body'>
							
						</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-danger">Submit</button>
						</div>
					</div>
				</form>
			</div>
		</div>

        <!-- Log details Modal -->
        <div class="modal fade" id="logDetailModal" tabindex="-1" role="dialog" aria-labelledby="logDetailModal" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logDetailModalTitle"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body logDetailModalBody">
            <!-- load content-->
            </div>
            <!-- <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div> -->
            </div>
        </div>
        </div>
        <!-- end-->

	</section>
    <!--/ Basic table -->
</div>

@endsection
@push('page-script')

<!--start flat picker -->
<script src="{{asset('public/flatpicker/flatpickr.min.js')}}"></script>
<!-- end-->
<!-- BEGIN: Page JS-->
{{-- <script src="{{asset('public/flatpicker/form-pickers.min.js')}}"></script> --}}
<!-- END: Page JS-->

<!-- tabel js-->
<script src="{{asset('public/esb_asset/vendors/js/tables/datatable/jquery.dataTables.min.js')}}"></script>
<script src="{{asset('public/esb_asset/vendors/js/tables/datatable/dataTables.bootstrap5.min.js')}}"></script>
<!-- end esb table js -->

<!-- select2 -->
<script src="{{asset('public/js/select2@4.0.13/dist/js/select2.min.js')}}"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js" integrity="sha256-AFAYEOkzB6iIKnTYZOdUf9FFje6lOTYdwRJKwTN5mks=" crossorigin="anonymous"></script> -->
<script src="{{asset('public/select2/js/script.js')}}"></script>
<!--end -->

<!-- Date Range Picker -->
<script src="{{ asset('public/plugins/moment/moment.min.js')}}"></script>
<script src="{{ asset('public/plugins/daterangepicker/daterangepicker.js')}}"></script>
<script type="text/javascript">
    $(function() {
        $('#date').daterangepicker({
            autoUpdateInput: false,
            locale: {
                cancelLabel: 'Clear'
			}
		});
		
        $('#date').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('MM/DD/YYYY') + ' - ' + picker.endDate.format('MM/DD/YYYY'));
            $(".datatables-auditLogs").DataTable().ajax.reload();
		});
		
        $('#date').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
            $(".datatables-auditLogs").DataTable().ajax.reload();
		});
	});
	
    function CallDateFilter(t){
        let selVal = $(t).val();
        let selDate = $("#date").val();
        if(selVal && selDate)
        {
            $(".datatables-auditLogs").DataTable().ajax.reload();
		}
		
	}
</script>
<!-- // Date Range Picker -->

<!-- BEGIN: Page JS-->
<!-- <script src="{{ asset('public/esb_asset/js/scripts/tables/datatable-auditLogs.js') }}"></script> -->
<script src="{{ asset('public/js/pages/audit_log/datatable-auditLogs_' .app('App\Utility\JsVersionDefination')::AUDIT_LOG) }}.js"></script>
<script src="{{ asset('public/js/pages/connection_setting/connection_settings_' .app('App\Utility\JsVersionDefination')::CONNECTION_SETTING) }}.js"></script>
<script src="{{ asset('public/js/integration_flow/integration_flow_' .app('App\Utility\JsVersionDefination')::INTEGRATIONFLOW) }}.js"></script>
<script src="{{ asset('public/js/pages/mapping_validation/mapping_validation_' .app('App\Utility\JsVersionDefination')::MAPPING_VALIDATION) }}.js"></script>
<!-- END: Page JS-->

<!--file upload js -->
<script src="{{asset('public/esb_asset/vendors/js/extensions/dropzone.min.js')}}"></script>
<!-- <script src="{{asset('public/esb_asset/js/scripts/forms/form-file-uploader.min.js')}}"></script> -->
<!-- end file upload-->

<script>
    // Audit Log Custom Filters
    $("#log_event").change(function (){
        $(".datatables-auditLogs").DataTable().ajax.reload();
	});
	
    $("#status").change(function (){
        $(".datatables-auditLogs").DataTable().ajax.reload();
	});
	
    $("#btnReload").click(function (){
        $('#date').trigger('cancel.daterangepicker');
        $("#log_event")[0].selectedIndex = 0;
        $("#status")[0].selectedIndex = 0;
        $(".datatables-auditLogs").DataTable().ajax.reload();
	});
</script>

<script>
	$(document).ready(function() {
		getFlowData();
		$("#TabClickStatus").val(1);
	});
	
	jQuery('.nav-tabs li a.nav-link').click(function(e) {
		event.preventDefault();
		jQuery(this).parents('.nav-tabs').find('.nav-link').removeClass("active");
		jQuery(this).addClass("active");
		
		const tabId = jQuery(this).attr('id');
		$("#activeTabData").val(tabId);
		
		let oldClickVal = $("#TabClickStatus").val();
		
		if (tabId == 'flows-tab') {
			getFlowData();
			} else if (tabId == 'connections-tab') {
			getConnectionData();
			openMapping(1);
		}
		if (oldClickVal == 1) {
			if (tabId == 'audit_log-tab') {
				$("#TabClickStatus").val(parseInt(oldClickVal) + 1);
                $(".datatables-auditLogs").DataTable().ajax.reload();
                $('body').tooltip({selector: '[data-toggle="tooltip"]'});
			}
		}
		
		jQuery(this).parents('.nav-tabs').next('.tab-content').find('.tab-pane').removeClass("active");
		jQuery(this).parents('.nav-tabs').next('.tab-content').find('.tab-pane[aria-labelledby=' + tabId + ']').addClass("active");
		
	});
	
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

<script>
    $(document).ready(function() {
		
        window.getUserWorkflowStatus = function( initial_flow=0 ){
            let checkedStatus="";
            var userIntegId = $('#input_IntegPlateformId').val();
            send_data = {'user_intg_id':userIntegId,"_token": $('meta[name="csrf-token"]').attr('content') };
			
            //check checked status
            if (localStorage.hasOwnProperty("workflow_initial_data_sync_status_userIntegId_"+userIntegId))
            {
                checkedStatus = localStorage.getItem("workflow_initial_data_sync_status_userIntegId_"+userIntegId);
			}

            if(!checkedStatus || initial_flow==1)
            {
                $.ajax({
					type:"POST",
					url: "{{url('check_user_workflow_status')}}",
					data: send_data,
					dataType: "json",
					success: function(res) {
						if(res.status_code == 1){
							var alertHTML = '';
							alertHTML += '<div class="alert alert-primary" role="alert">';
                            //Initial data sync from '+res.status_text+' to our record has been started. Please wait for sometime.
							alertHTML += '<div class="alert-body">Your initial sync is in process. You will be sent an email when this sync is completed.<strong>';
							alertHTML += '<br><span style="font-size:11px;">This message will disappear once the process completed.</span></div>';
							alertHTML += '</div>';
							$('#alertBox').html('');
							$('#alertBox').append(alertHTML);
							localStorage.removeItem("workflow_initial_data_sync_status_userIntegId_"+userIntegId);
						}
						else if(res.status_code == 0){
							$('#alertBox').html('');
							
							//write in local storage
							localStorage.setItem("workflow_initial_data_sync_status_userIntegId_"+userIntegId, "checked");
						}
					},
					error: function (jqXHR, textStatus, errorThrown) {
						if (jqXHR.status == 500) {
							errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
							} else {
							errorNotify('Unexpected error Please try again.','Failed');
						}
					}
				});
			}
		}
		
        // To Call function on page load
        getUserWorkflowStatus(1);
		
        // To call function on specific time intervals
        var interval = 30000; // 30000 means every 30 seconds. or use : 1000 * 60 * 5; // where 5 is your every 5 minutes
        setInterval(getUserWorkflowStatus, interval);
	});
	
    // $(document).ready(function() {
    //     var view_logs = "{{ $module_rights['view_logs'] }}";
    //     var view_integrations = "{{ $module_rights['view_integrations'] }}";
    //     var modify_integrations = "{{ $module_rights['modify_integrations'] }}";
	
    //     if(view_integrations == 1 && view_logs == 1){
    //         $("#audit_log-tab").parents('.nav-tabs').find('.nav-link').removeClass("active");
	// 	    $("#audit_log-tab").addClass("active");
    //         $("#audit_log").parents('.tab-contents').find('.tab-pane').removeClass("active");
	// 	    $("#audit_log").addClass("active");
    //         $("#TabClickStatus").val(parseInt(oldClickVal) + 1);
    //         $(".datatables-auditLogs").DataTable().ajax.reload();
    //         $('body').tooltip({selector: '[data-toggle="tooltip"]'});
    //     }
    // });
	
    //show hide mapping field based on user access
    function checkUserAccess()
    {
        var view_logs = "{{ $module_rights['view_logs'] }}";
        var view_integrations = "{{ $module_rights['view_integrations'] }}";
        var modify_integrations = "{{ $module_rights['modify_integrations'] }}";
        //staff can view mapping data only
        if ( view_integrations==1 && modify_integrations==0 )
        {
            $(".form-control-map").prop("disabled", true);
            $(".select2-hidden-accessible").prop("disabled", true);
            $(".addMappingField").hide();
            $(".removeMappingField").hide();
		}
	}
	
    function handleDataRetention()
    {
		var ischecked = $("#data_retention_switch").is(':checked');
        if (!ischecked) {
            $(".data_retention_period_section").hide();
		}
        else {
			$(".data_retention_period_section").show();
		}
	}
</script>
@endpush