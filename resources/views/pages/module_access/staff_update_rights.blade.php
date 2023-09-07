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

@section('title', 'Update Staff Member')

@section('side-bar')
@include('layouts.menu-bar')
@include('layouts.mapping-sidebar')
@endsection

@section('breadcrumb')
{{-- <li class="breadcrumb-item">
    <a href="{{ url('manage-staff') }}">Manage Staff</a>
</li> --}}
<li class="breadcrumb-item active">Update Staff Member</li>
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
    .field-msg{
		margin-top: 8px;
		font-size: 14px;
		font-style: italic;
		color: dimgrey;
	}
    .err_msg {
		color: #f00;
		display: none;
		font-size: x-small;
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
				<!-- Content Starts Here -->
                <div class="card">

                    <!-- /.card-header -->
                    @if(Session::has('failM'))
                        <span class="invalid-feedback text-center" style="display: block;font-size: 18px; color: black;" role="alert">
                            <strong>{{ Session::pull('failM') }}</strong>
                        </span>
                    @endif
                    <!-- form start -->
                    <form role="form" enctype="multipart/form-data" id="memberUpdateForm" role="form" method="POST" action="" autocomplete="off">
                        <div class="card-header" style="padding-bottom: 0% !important; background: #F3F2F7;">
                            <div class="col-10 col-sm-10 col-lg-10">
                                <h5>{{$user->name}} ({{$user->email}})</h5>
                            </div>
                            <div class="col-2 col-sm-2 col-lg-2">
                                <div class="form-group">
                                    <div class="custom-control custom-switch custom-switch-off-danger custom-switch-on-success" >
                                        @if ($modify == 1)
                                            @if($user->status == 1)
                                                <input type="checkbox" class="custom-control-input" id="active" checked>
                                                <label class="custom-control-label" for="active" id="status_label">Active</label>
                                            @else
                                                <input type="checkbox" class="custom-control-input" id="active">
                                                <label class="custom-control-label" for="active" id="status_label">Inactive</label>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body" style="overflow-y: auto;">
                            <div class="form-group">
                                <div class="row">
                                    <input type="hidden" name="update_id" id="update_id" value="{{$user->id}}"/>
                                    <input type="hidden" name="name" id="name" value="{{$user->name}}">
                                    <input type="hidden" name="email" id="email" value="{{$user->email}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <!-- /.row -->
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card">
                                        <div class="card-header">
                                            <h3 class="card-title">Module Rights</h3>
                                        </div>
                                        <!-- /.card-header -->
                                        <div class="card-body table-responsive p-0" ><!-- style="height:250px; overflow-y:auto;"-->
                                            <table class="table table-head-fixed text-nowrap table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Module Name</th>
                                                        <th>View</th>
                                                        <th>Modify</th>
                                                    </tr>
                                                </thead>
                                                <tbody>

                                                @for ($i=0; $i<count($module_id); $i++)
                                                <tr>
                                                    <td>{{ $i+1 }}</td>
                                                    <td>{{ $module_name[$i] }}<input type="hidden" name="module_id[]" value="{{ $module_id[$i] }}" ></td>
                                                    <td><input type="checkbox" name="view[]" value="1" @if($arrView[$i] == 1) checked @endif  @if($option_view[$i] == 0) hidden @endif></td>
                                                    <td><input type="checkbox" name="modify[]" value="1"  @if($arrModify[$i] == 1) checked @endif  @if($option_modify[$i] == 0) hidden @endif></td>
                                                </tr>
                                                @endfor

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
                            @if ($modify == 1 && $user->status == 1)
                            <button type="button" class="btn btn-primary" id="btnSave">Submit</button>
                            @endif
                        </div>
                    </form>
                </div>
                <!-- /.card -->
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

<!-- bs-custom-file-input -->
<script src="{{ asset('public/plugins/bs-custom-file-input/bs-custom-file-input.min.js')}}"></script>
<script type="text/javascript">
    $(document).ready(function () {
        bsCustomFileInput.init();
    });
</script>
<!-- JS to close alert-box after 4 seconds -->
<script>
    $(".alert").delay(4000).slideUp(200, function() {
        $(this).alert('close');
    });
</script>
<script>
    // Function to update a user
    $(document).on('click','#btnSave',function(){
        $('.err_msg').hide();
        name = $('#name').val().trim();
        email = $('#email').val().trim();
        update_id = $('#update_id').val().trim();
        err = false;
        if(!name){
            $('#name').next().show();
            $('#name').addClass('is-invalid');
            $('#name').focus();
            err = true;
        }
        if(!email){
            $('#email').next().show();
            $('#email').addClass('is-invalid');
            $('#email').focus();
            err = true;
        }
        if(err)
            return false;

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

        pdata = new FormData($("#memberUpdateForm")[0]);
        pdata.append("name", name);
        pdata.append("email", email);
        pdata.append("update_id", update_id);
        pdata.append("access_info", JSON.stringify(access_info));
        pdata.append("_token", $('meta[name="csrf-token"]').attr('content'));
        showOverlay();
        $.ajax({
            url: "{{url('staff_update_rights')}}",
            data: pdata,
            dataType: "json",
            async: true,
            type: "post",
            processData: false,
            contentType: false,
            success: function (res) {
                hideOverlay();
                if (res.status_code == "1") {
                    successNotify(res.status_text,'Success');
                }else{
                    errorNotify(res.status_text,'Failed');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                hideOverlay();
                if (jqXHR.status == 500) {
                    errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
                } else {
                    errorNotify('Unexpected error Please try again.','Failed');
                }
            }
        });
    });

    // Function to activate - deactivate user
    $(document).on('click','#active',function(){
        $update_id = $('#update_id').val().trim();
        if($("#active").prop("checked") == true){
            $chkVal = 1;
        }
        else if($("#active").prop("checked") == false){
            $chkVal = 0;
        }
        send_data = {'check_val':$chkVal,'update_id':$update_id,"_token": "{{ csrf_token() }}"};
        $("#memberUpdateForm :input").prop("disabled", true);
        $("body").css("cursor", "progress");
        $.ajax({
            type:"POST",
            url: "{{url('delete_staff_member')}}",
            data: send_data,
            dataType: "json",
            success: function(res) {
                $("#memberUpdateForm :input").prop("disabled", false);
                $("body").css("cursor", "default");
                if(res.status_code==1){
                    successNotify(res.status_text,'Success');
                    window.location='{{ url('manage-staff')}}';
                    /*if($chkVal == 0){
                        $("#status_label").text('Inactive');
                    }
                    else{
                        $("#status_label").text('Active');
                    }*/
                }else if(res.status_code==0){
                    errorNotify(res.status_text,'Failed');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                hideOverlay();
                if (jqXHR.status == 500) {
                    errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
                } else {
                    errorNotify('Unexpected error Please try again.','Failed');
                }
            }
        });
    });

    // Hide error after giving data to inputs
    $(document).ready(function(){
        $('#memberUpdateForm').on('change keyup', ':input', function(e) {
            err = false;
            if(!$(this).val()){
                $(this).next().show();
                $(this).addClass('is-invalid');
                err = true;
            } else{
                $(this).next().hide();
                $(this).removeClass('is-invalid');
                err = false;
            }
            if(err)
                return false;
        });
    });

    // Function to enable or disable form as per user right
    $(document).ready(function(){
        var modify = '{{$modify}}';
        if(modify == 0){
            $("#memberUpdateForm :input").prop("disabled", true);
            $("#active").prop("disabled", true);
        }
    });
</script>
@endpush
