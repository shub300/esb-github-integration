@extends('layouts.master')

@section('head-content')

<style>
	
</style>

@endsection

@section('title', 'User Profile')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">User Profile</li>
@endsection

@push('page-style')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
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
	
	.boxStyle1 img {
	width: 100%;
	}
	
	.boxStyle1:hover {
	box-shadow: 0 4px 24px 0 rgb(94 80 238 / 50%);
	}
	
	.oneLineText {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	}
	
	.border-top-blue {
	border-top: 1px solid #c6c1f9 !important;
	}
	
	.border-end-blue {
	border-right: 1px solid #c6c1f9 !important;
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
    .err_msg {
	color: #f00;
	display: none;
	font-size: small;
    }
    .pwd-label{
	margin-top: 10px;
    }
</style>
@endpush

@section('page-content')
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<section id="page-account-settings" style="margin-top:-20px !important">
    <div class="row">
        <!-- left menu section -->
        <div class="col-md-3 mb-2 mb-md-0">
            <ul class="nav nav-pills flex-column nav-left">
                <!-- general -->
                <li class="nav-item"><a class="nav-link active" id="account-pill-general" data-toggle="pill" href="#account-vertical-general" aria-expanded="true"><i data-feather="user" class="font-medium-3 mr-1"></i><span class="font-weight-bold">General</span></a></li>
				@if(!Session::has('switch_to_user_dashboard'))
                <li class="nav-item"><a class="nav-link" id="account-pill-password" data-toggle="pill" href="#account-vertical-password" aria-expanded="false"><i data-feather="lock" class="font-medium-3 mr-1"></i> <span class="font-weight-bold">Change Password</span></a></li>
				@endif
                <li class="nav-item"><a class="nav-link" id="account-pill-notification" data-toggle="pill" href="#account-vertical-notification" aria-expanded="false"><i data-feather="mail" class="font-medium-3 mr-1"></i> <span class="font-weight-bold">Error Email Notification</span></a></li>
			</ul>
		</div>
        <!--/ left menu section -->
		
        <!-- right content section -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-body">
                    <div class="tab-content">
						
                        <!-- general tab -->
                        <div role="tabpanel" class="tab-pane active" id="account-vertical-general" aria-labelledby="account-pill-general" aria-expanded="true">
                            @if($timezoneInfo)
							<input type="hidden" id="selectedZoneInfo" value="{{ str_replace('.',':',$timezoneInfo->text) }}">
                            @else
							<input type="hidden" id="selectedZoneInfo" value="Search for timezone">
                            @endif
							
							
                            <!-- form -->
                            <form class="validate-form mt-2" novalidate="novalidate" id="general_setting_form">
								@csrf
                                <div class="row">
                                    <div class="col-12 col-sm-12">
                                        <div class="mb-1">
                                            <div class="row">
                                                <div class="col-sm-3">
                                                    <label class="form-label pwd-label">Select Time Zone</label>
												</div>
                                                <div class="col-sm-7">
													<select class="timezoneDataList" name="timezone">
													</select>
												</div>
											</div>
										</div>
									</div>
                                    
                                    <div class="col-12">
                                        <button type="button" class="btn btn-primary mt-2 me-1 waves-effect waves-float waves-light timezone_update">Save changes</button>
                                        {{-- <button type="reset" class="btn btn-outline-secondary mt-2 waves-effect">Cancel</button> --}}
									</div>
								</div>
							</form>
                            <!--/ form -->
						</div>
                        <!--/ general tab -->
							@if(!Session::has('switch_to_user_dashboard'))
                        <!-- change password -->
                        <div class="tab-pane fade" id="account-vertical-password" role="tabpanel" aria-labelledby="account-pill-password" aria-expanded="false">
                            <!-- form -->
                            <form class="validate-form" novalidate="novalidate" id="password_sec">
                                <div class="row">
                                    <div class="col-12 col-sm-12">
                                        <div class="mb-1">
                                            <div class="row">
                                                <div class="col-sm-3">
                                                    <label class="form-label pwd-label">Current Password</label>
												</div>
                                                <div class="col-sm-7">
                                                    <div class="input-group form-password-toggle input-group-merge">
                                                        <input type="password" id="current_password" name="current_password" class="form-control pwds" placeholder="Current Password">
                                                        <div class="input-group-text cursor-pointer">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
														</div>
													</div>
                                                    <span class="err_msg" id="opwd_err_msg">Field should not be empty.</span>
												</div>
											</div>
										</div>
									</div>
									
                                    <div class="col-12 col-sm-12">
                                        <div class="mb-1">
                                            <div class="row">
                                                <div class="col-sm-3">
                                                    <label class="form-label pwd-label">New Password</label>
												</div>
                                                <div class="col-sm-7">
                                                    <div class="input-group form-password-toggle input-group-merge">
                                                        <input type="password" id="new_pass" name="new_pass" class="form-control pwds" placeholder="New Password">
                                                        <div class="input-group-text cursor-pointer">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
														</div>
													</div>
                                                    <span class="err_msg" id="pwd_err_msg">Password must be at least 6 character long.</span>
												</div>
											</div>
										</div>
									</div>
									
                                    <div class="col-12 col-sm-12">
                                        <div class="mb-1">
                                            <div class="row">
                                                <div class="col-sm-3">
                                                    <label class="form-label pwd-label">Retype New Password</label>
												</div>
                                                <div class="col-sm-7">
                                                    <div class="input-group form-password-toggle input-group-merge">
                                                        <input type="password" id="confirm_pass" name="confirm_pass" class="form-control pwds" placeholder="Confirm Password">
                                                        <div class="input-group-text cursor-pointer">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
														</div>
													</div>
                                                    <span class="err_msg" id="cpwd_err_msg">New Password and Confirmation Password must be the same.</span>
												</div>
											</div>
										</div>
									</div>
									
                                    <div class="col-12">
                                        <button type="button" class="btn btn-primary me-1 mt-1 waves-effect waves-float waves-light update_pass">Save changes</button>
                                        <a class="btn btn-outline-secondary mt-1 waves-effect" href="{{ url('/') }}">Cancel</a>
									</div>
								</div>
							</form>
                            <!--/ form -->
						</div>
                        <!--/ change password -->
						@endif
						
                        <!-- notification tab -->
                        <div role="tabpanel" class="tab-pane" id="account-vertical-notification" aria-labelledby="account-pill-notification" aria-expanded="true">
                            <!-- form -->
                            <form class="validate-form" novalidate="novalidate" id="notification_setting_form">
                                @csrf
                                <div class="row">
                                    <div class="col-12 col-sm-12">
                                        <div class="mb-1">
                                            <div class="row">
                                                <div class="col-sm-3">
                                                    <label class="form-label pwd-label">Add Email address(es) to get the Error Email notification report once a day <small>(multiple can be added, use comma seperation)</small></label>
												</div>
                                                <div class="col-sm-7">
                                                    <textarea name="emails" class="form-control">{{ @$notification_email->emails }}</textarea>
                                                    <small class="text-danger animated emails fadeInUp notification_setting_form"></small>
												</div>
											</div>
										</div>
									</div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary mt-2 me-1 waves-effect waves-float waves-light updateNotificationEmail">Save changes</button>
									</div>
								</div>
							</form>
                            <!--/ form -->
						</div>
                        <!--/ notification tab -->
					</div>
				</div>
			</div>
		</div>
        <!--/ right content section -->
	</div>
</section>
@endsection

@push('page-script')
<script src="{{asset('public/esb_asset/js/scripts/pages/page-account-settings.min.js')}}"></script>
<script>
    // JS: User Profile
    $(".profile_update").click(function (){
	$('.err_msg').hide();
	var name = $('.name').val().trim();
	
	err = false;
	if(!name){
	$('.name').next().show();
	err = true;
	}
	if(err)
	return false;
	
	pdata = new FormData($("#profile_sec")[0]);
	pdata.append("name", name);
	
	showOverlay();
	$.ajax({
	url: "{{url('update-profile')}}",
	data: pdata,
	dataType: "json",
	async: true,
	type: "post",
	processData: false,
	contentType: false,
	success: function (res) {
	hideOverlay();
	$(this).attr('disabled',false);
	if(res.status_code==1){
	$('.info > .d-block').text(res.name);
	successNotify(res.status_text,'Success');
	}else if(res.status_code==0){
	errorNotify(res.status_text,'Failed');
	}
	else if(res.status_code==2){
	successNotify(res.status_text,'Info');
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
	
    // JS: Password section
    $("#new_pass").keyup(function (){
	var pass = $(this).val();
	if(pass.length >= 6){
	$(this).removeClass('is-invalid');
	$('#pwd_err_msg').hide();
	return true;
	}
	else{
	$(this).addClass('is-invalid');
	$('#pwd_err_msg').show();
	}
    });
	
    $("#confirm_pass").keyup(function (){
	var new_password = $("#new_pass").val();
	var confirm_password = $(this).val();
	if(new_password == confirm_password){
	$(".update_pass").attr("disabled",false);
	$(this).removeClass('is-invalid');
	$(this).addClass('is-valid');
	$('#cpwd_err_msg').hide();
	}
	else{
	$(this).addClass('is-invalid');
	$(this).removeClass('is-valid');
	$('#cpwd_err_msg').show();
	}
    });
	
    $(".update_pass").click(function (){
	$('#pwd_err_msg').hide();
	$('#cpwd_err_msg').hide();
	var current_password = $('#current_password').val().trim();
	var new_pass = $('#new_pass').val().trim();
	var confirm_pass = $('#confirm_pass').val().trim();
	
	err = false;
	if(!current_password){
	$('#opwd_err_msg').show();
	err = true;
	}
	if(!new_pass){
	$('#pwd_err_msg').show();
	err = true;
	}
	if(new_pass != confirm_pass){
	$('#confirm_pass').addClass('is-invalid');
	$('#confirm_pass').removeClass('is-valid');
	$('#cpwd_err_msg').show();
	rr = true;
	}
	if(err)
	return false;
	
	//$new_pass = new_pass;
	showOverlay();
	$(".update_pass").attr('disabled',true);
	send_data = {'current_password':current_password,'password':new_pass,"_token": "{{ csrf_token() }}"};
	$.ajax({
	type:"POST",
	url: "{{url('update-password')}}",
	data: send_data,
	dataType: "json",
	success: function(res) {
	hideOverlay();
	$(".update_pass").attr('disabled',false);
	$('#new_pass,#confirm_pass').val('').trigger('input');
	$('#new_pass,#confirm_pass').nextAll('small.help-block').css('display','none');
	$('.progress-bar').attr('style', "width: 0%");
	if(res.status_code==1){
	successNotify(res.status_text,'Success');
	}else if(res.status_code==0){
	errorNotify(res.status_text,'Failed');
	}
	$('#confirm_pass').removeClass('is-valid');
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
	
    $("#new_pass").blur(function (){ $('#new_pass').trigger('keyup'); });
    $("#confirm_pass").blur(function (){ $('#confirm_pass').trigger('keyup'); });
</script>

<!-- Progress-bar JS -->
<script>
    var percentage = 0;
	
    function check(n, m) {
        if (n < 6) {
            percentage = 0;
            $(".progress-bar").css("background", "#dd4b39");
			} else if (n < 8) {
            percentage = 20;
            $(".progress-bar").css("background", "#9c27b0");
			} else if (n < 10) {
            percentage = 40;
            $(".progress-bar").css("background", "#ff9800");
			} else {
            percentage = 60;
            $(".progress-bar").css("background", "#4caf50");
		}
		
        // Check for the character-set constraints
        // and update percentage variable as needed.
		
        //Lowercase Words only
        if ((m.match(/[a-z]/) != null))
        {
            percentage += 10;
		}
		
        //Uppercase Words only
        if ((m.match(/[A-Z]/) != null))
        {
            percentage += 10;
		}
		
        //Digits only
        if ((m.match(/0|1|2|3|4|5|6|7|8|9/) != null))
        {
            percentage += 10;
		}
		
        //Special characters
        if ((m.match(/\W/) != null) && (m.match(/\D/) != null))
        {
            percentage += 10;
		}
		
        // Update the width of the progress bar
        $(".progress-bar").css("width", percentage + "%");
	}
	
    // Update progress bar as per the input
    $(document).ready(function() {
        // Whenever the key is pressed, apply condition checks.
        $("#new_pass").keyup(function() {
            var m = $(this).val();
            var n = m.length;
			
            // Function for checking
            check(n, m);
		});
	});
</script>
<!--// Progress-bar JS -->

<!-- Dropify: image uploads js-->
<script src="{{asset('public/plugins/dropify/js/dropify.min.js')}}"></script>
<script type="text/javascript">
    $('.dropify').dropify({
        messages: {
            'default': 'Drag and drop a file here or click',
            'replace': 'Drag and drop or click to replace',
            'remove': 'Remove',
            'error': 'Oops, something wrong appended.'
		},
        error: {
            'fileSize': 'The file size is too big (1M max).'
		}
	});
</script>
<!-- // Dropify -->

<script>
	
	$('.timezoneDataList').select2({
		ajax: {
			url: $("#AjaxCallUrl").val() +'/get_timezone_list',
			dataType: 'json',
            delay: 250,
			processResults: function (data) {
				return {
					results: data.items
				}
			},
		},
		placeholder: $("#selectedZoneInfo").val(),
        minimumInputLength: 1,	
	});
	
    $(".timezone_update").click(function (){
        let timezone = $(".timezoneDataList").val();
        if(timezone)
        {
            pdata = new FormData($("#general_setting_form")[0]);
            showOverlay();
            $.ajax({
                url: "{{url('update_timezone')}}",
                data: pdata,
                dataType: "json",
                async: true,
                type: "post",
                processData: false,
                contentType: false,
                success: function (res) {
                    hideOverlay();
                    if(res.status_code==1){
                        successNotify(res.status_text,'Success');
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
		} 
        else
        {
            errorNotify('Please select new timezone to update','Failed');
		}
	});
	
    $(document.body).on('submit', '#notification_setting_form', function(){
        event.preventDefault();
		$(".updateNotificationEmail").prop('disabled', true);
		$('.notification_setting_form').html('');
		
		var data=$(this).serialize();
		showOverlay();
		$.ajax({
			url: "{{ URL('update-notification-email') }}",
			type:'POST',
			data: data,
            dataType: "json",
			success: function(data) {
				$(".updateNotificationEmail").prop('disabled', false);
				if(data.status_code==1)
                {
                    successNotify(data.status_text,'Success');
				}
                else
                {
                    $(".emails").html("notification email field is required.");
                    errorNotify(data.status_text,'Failed');
				}
				hideOverlay();
			},
			error: function(XMLHttpRequest, textStatus, errorThrown) {
				$.each(XMLHttpRequest.responseJSON.errors, function(key, value) {
                    $("."+key).html(value);
					errorNotify(value, 'Failed');
				});
				$(".updateNotificationEmail").prop('disabled', false);
				hideOverlay();
			}
		});
	});
</script>
@endpush