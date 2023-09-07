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

@section('title', 'Invite Staff Member')

@section('side-bar')
@include('layouts.menu-bar')
@include('layouts.mapping-sidebar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Invite Staff Member</li>
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
    .fa-info-circle{
        font-size: 14px;
        padding-left: 3px;
        padding-top: 2px;
    }
    .with-errors {
        color: #ff0000;
        font-size:small;
        text-align: left;
    }
    .with-errors li{
	    color: #D91C03 !important
    }

</style>

@endpush

@section('page-content')
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">

<div class="content-body" style="margin-top:-25px !important">
	<section id="basic-tabs-components">
        <!-- Flash Message -->
        @foreach (['danger', 'warning', 'success', 'info'] as $msg)
            @if(Session::has('alert-' . $msg))
            <div class="alert alert-{{ $msg }}" id="success-alert">
                <button type="button" class="close" data-dismiss="alert">x</button>
                <strong>{{ Session::get('alert-' . $msg) }}</strong>
            </div>
            @endif
        @endforeach
        <!--// Flash Message -->

		<div class="row match-height">
			<!-- Basic Tabs starts -->
			<div class="col-xl-12 col-lg-12">
				<div class="card">
                    <!-- Alert Message -->
                    @if(Session::has('failM'))
                    <script>
                        var message = "{{ Session::pull('failM') }}";
                        setTimeout(function () {
                            callToastr('fail', message);
                        }, 1000);
                    </script>
                    @endif
                    @if(Session::has('successM'))
                    <script>
                        var message = "{{ Session::pull('successM') }}";
                        setTimeout(function () {
                            callToastr('success', message);
                        }, 1000);
                    </script>
                    @endif
                    <!-- // Alert Message -->

                    <!-- form start -->
                    <form role="form" id="invitationForm" data-toggle="validator" onsubmit="return UpdateForm()" action="{{ url('send_invitation_mail') }}" method="post" autocomplete="off">
                        @csrf
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label style="display: flex !important;text-align:left !important; font-weight:bold">Full Name&nbsp;<i class="fa fa-info-circle" id="infoMemberName"></i></label>
                                        <input type="text" class="form-control b-1 @error('name') is-invalid @enderror" name="name" id="name" placeholder="Enter staff's name" required>
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label style="display: flex !important;text-align:left !important; font-weight:bold">Email address&nbsp;<i class="fa fa-info-circle" id="infoMemberEmail"></i></label>
                                        <input type="text" class="form-control b-1 @error('email') is-invalid @enderror" name="email" id="email" data-error="Your email address is invalid" placeholder="Enter email" required>
                                        <div class="help-block with-errors"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <!-- /.row -->
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card">
                                        <!-- /.card-header -->
                                        <input type="hidden" name="access_info" id="access_info"/>
                                        <div class="card-body table-responsive p-0" ><!-- style="height: 300px;" for scroll -->
                                            <table class="table table-head-fixed text-nowrap table-hover table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Module Name</th>
                                                        <th>View</th>
                                                        <th>Modify</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                @foreach ($modules as $mod)
                                                    <tr>
                                                        <td>{{$loop->index+1}}</td>
                                                        <td>{{$mod->module_name}}<input type="hidden" name="module_id[]" value="{{$mod->id}}"></td>
                                                        <td><input type="checkbox" name="view[]" @if($mod->option_view != 1) hidden @endif value="1"></td>
                                                        <td><input type="checkbox" name="modify[]" @if($mod->option_modify != 1) hidden @endif value="1"></td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <!-- /.card-body -->
                                        </div>
                                        <!-- /.card -->
                                    </div>
                                </div>
                                <!-- /.row -->
                            </div>
                        </div>
                        <!-- /.card-body -->
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary" id="btnSubmit">Send Invite</button>
                        </div>
                    </form>
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
<!-- Validator js -->
<script src="{{asset('public/login_assets/js/validator.min.js')}}"></script>
<script>
    $('#invitationForm').validator().on('submit', function (e) {
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

    <!-- Tooltip as introduction-->
    <script>
        $(document).ready(function(){
            $('.fa-info-circle').attr('data-toggle', 'tooltip');
            $("#infoMemberName").attr("title", "This text will appear in the invitation mail as member name." );
            $("#infoMemberEmail").attr("title", "Invitation mail will be delivered to this email address." );
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>

    <!-- JS to close alert-box after 4 seconds -->
    <script>
        $(".alert").delay(4000).slideUp(200, function() {
            $(this).alert('close');
        });

        function UpdateForm(){
            access_info = [];
            $('[name="module_id[]"]').each(function(){
                module_val = $(this).val();
                access_obj = {'module_id':module_val};
                access_obj.view = 0;
                access_obj.modify = 0;
                if($(this).closest('tr').find('[name="view[]"]').is(':checked')){
                    access_obj.view = 1;
                }
                if($(this).closest('tr').find('[name="modify[]"]').is(':checked')){
                    access_obj.modify = 1;
                }
                access_info.push(access_obj);
            })
            $('#access_info').val(JSON.stringify(access_info));
        }
    </script>

    <script>
        function callToastr(type, msg){
            if(type == 'success'){
                toastr.success(msg, {timeOut: 5000});
            }
            else{
                toastr.error(msg, {timeOut: 5000});
            }
        }
    </script>
@endpush
