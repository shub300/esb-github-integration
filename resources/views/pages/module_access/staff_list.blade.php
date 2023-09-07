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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" integrity="sha256-FdatTf20PQr/rWg+cAKfl6j4/IY3oohFAJ7gVC3M34E=" crossorigin="anonymous">
<link rel="stylesheet" type="text/css" href="{{asset('public/select2/css/select2-bootstrap4.css')}}">
<style>
	.select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
		text-align: left !important;
	}
</style>
<!-- end select2-->

@endsection

@section('title', 'Manage Staff')

@section('side-bar')
@include('layouts.menu-bar')
@include('layouts.mapping-sidebar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Manage Staff</li>
@endsection

@push('page-style')
<!--css to hide Export and other toolbar buttons -->
<style>
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
</style>

@endpush

@section('page-content')
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">

<div class="content-body">
	<section id="basic-tabs-components">
		<div class="row match-height">
			<!-- Basic Tabs starts -->
			<div class="col-xl-12 col-lg-12">
				<div class="card" style="margin-top: -30px !important;">
                    <div class="card-body">
                        <!-- table audit Log -->
                        <section id="basic-datatable2">
                            <div class="row">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="dataTables_length">
                                            <div class="row">
                                                <div class="col-sm-12 col-xs-12 col-md-5">
                                                    <label>Status</label>
                                                    <select id="status" style="margin-bottom: 4px;" class="custom-select custom-select-sm form-control form-control-sm">
                                                        <option value="1">Active</option>
                                                        <option value="0">Inactive</option>
                                                    </select>
                                                </div>

                                                <div class="col-sm-12 col-xs-12 col-md-7" style="margin-top: 25px;">
                                                    <button class="btn btn-primary btn-sm" onclick="window.location='{{ url('invite-staff-member') }}'" @if($modify!=1) hidden @endif>Invite a member</button>
                                                </div>

                                            </div>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-hover table-sm" id="tbl_staff_list">
                                                <thead>
                                                    <tr>
                                                        <th>Sl No</th>
                                                        <th>Name</th>
                                                        <th>Email</th>
                                                        <th>Status</th>
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
			    </div>
		    </div>
        </div>

        <!-- Modal: Staff Delete -->
        <div class="modal fade col-xs-12" id="mdl_staff_delete" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
            aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header d-flex align-items-center">
                        <h4 class="modal-title" id="exampleModalLabel1">Deactivate Staff</h4>
                        <button type="button" class="close ml-auto" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure that you want to Deactivate <b id="deactivate_name"></b> ?</p>
                        <input type="hidden" id="user_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger deactivate_btn">
                            Deactivate
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </section>
    <!--/ Basic table -->
</div>

@endsection
@push('page-script')
<!--start flat picker -->
<script src="{{asset('public/flatpicker/flatpickr.min.js')}}"></script>
<!-- end-->

<!-- tabel js-->
<script src="{{asset('public/esb_asset/vendors/js/tables/datatable/jquery.dataTables.min.js')}}"></script>
<script src="{{asset('public/esb_asset/vendors/js/tables/datatable/dataTables.bootstrap5.min.js')}}"></script>
<!-- end esb table js -->

<!-- Custom JS-->
<script src={{ asset('public/esb_asset/js/scripts/tables/datatable-staffList.js') }}></script>

@endpush
