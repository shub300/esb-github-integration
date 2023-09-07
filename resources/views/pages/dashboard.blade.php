@extends('layouts.master')

@section('head-content')

<style>
.main-menu .navbar-header {
	height: auto !important;
}
.btn-primary:focus, 
.btn-primary:active{
	background-color:{{ isset($org_style->primary_button_hover_color) ? $org_style->primary_button_hover_color.' !important' : ''}};
}
</style>

@endsection

@section('title', 'Integrations')
@section('title2', 'Select the “Setup Integration” option for any of the below to begin setting up that integration')


@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Integrations</li>
@endsection

@push('page-style')
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



	.myApp-item {
		background: linear-gradient(45deg, #dcdaff, #c2bfef);
		overflow: hidden;
	}

	/* .pagination
	{
		width: 100%;
    	justify-content: center;
	}  */
	.scrollLoader {
		position: absolute;
		right: 50%;
		bottom: 0;
		z-index: 1;
		height: 100px;
	}

	.jscroll-inner {
		display: flex;
		flex-wrap: wrap;
		width: 100%;
	}

	.jscroll-added>.infinite-scroll>.col-md-12 {
		padding: 0px 25px 10px 25px;
		;
	}

	.jscroll-added {
		width: 100%;
	}

	.IntegrationSearchIcon {
		font-size: 20px !important;
		background-color: #2C6FA8 !important;
		padding: 9px !important;
		color: white !important;
	}

	.alert-warning {
		color: #856404;
		background-color: #fff3cd;
		border-color: #ffeeba;
	}
       
	.alert {
		position: relative;
		padding: 0.75rem 1.25rem;
		margin-bottom: 1rem;
		border: 1px solid transparent;
		border-radius: 0.25rem;
	}

</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<link rel="stylesheet" type="text/css" href="{{asset('public/select2/css/select2-bootstrap4.css')}}">


@endpush

@section('page-content')
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">


@if($modify == 1)
<div class="row" style="display:flex;justify-content: right;">

	<div class="col-md-4 col-xs-12" style="margin-right: 15px;
    margin-left: 27px;
    padding-left: 0px;">

		<input type="search" class="form-control ds-input search_integration" placeholder="Search for integrations..."
			aria-label="Search for..." autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list"
			aria-expanded="false" aria-owns="algolia-autocomplete-listbox-0" dir="auto"
			style="position: relative; vertical-align: top;" onkeyup="loadApps(this)">



	</div>
</div>
<br>
@endif

<div class="row">
	<div class="col-md-12 sectionApplistBySearch" style="display: flex !important;flex-wrap: wrap !important;">
	</div>
</div>

<!--alert for failed-->
@if(isset($record_failed_alert_msg) && $record_failed_alert_msg !="")
<div class="alert alert-warning alert-dismissible fade show" role="alert">
  <strong>Hi {{Auth::user()->name}}!</strong> You have some Failed record please check {!!$record_failed_alert_msg!!} Integrations to resolve it & see the reason of failure
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>
@endif


<div class="row infinite-scroll">
	@if(count($integration) < 1)
	 <div class="col-xl-12 col-md-12 col-12">
		<div class="card boxStyle1">
			<div class="card-body p-0">
				<div class="row">
					<div class="col-12 py-1">
						<h5 class="text-center">No integration available</h5>
					</div>
				</div>
			</div>
		</div>
</div>
@endif

<div class="col-md-12 sectionApplist" style="display: flex !important;flex-wrap: wrap !important;">
	@foreach($integration as $wv)
	<div class="col-xl-4 col-md-4 col-12">
		<div class="card boxStyle1">
			<div class="card-header flex-column align-items-center pb-0">

				<div class="row justify-content-center align-items-center py-1 p-relative">
					<div class="connect-app"><span></span></div>
					<div class="col mb-xl-0 text-center">
						<div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
							<img class="icon" src="{{env('CONTENT_SERVER_PATH').($wv->p1_image)}}">
						</div>
					</div>
					<div class="col mb-xl-0 text-center">
						<div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
							<img class="icon" src="{{env('CONTENT_SERVER_PATH').($wv->p2_image)}}">
						</div>
					</div>
				</div>

				<h4 class="fw-bolder mt-1">{{$wv->p1_name}} + {{$wv->p2_name}}</h4>
				<p class="card-text mb-1">
					{{-- <small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random
						Lipsum generator.</small> --}}
				</p>
				@if($modify == 1)
				<span><button type="button" data-id="{{$wv->integration_id}}"
						class=" mb-1 btn btn-primary activate-flow waves-effect waves-float waves-light">Setup
						Integration</button></span>
				@endif
			</div>
			<div class="card-body p-0">
				<div id="goal-overview-chart"></div>
				<div class="row border-top-blue text-center mx-0">
					<!--<div class="col-6 border-end-blue py-1">
					<h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
				  </div>  -->
					<div class="col-12 py-1">
						<!-- <h3><a href="#">
								<h5 class="fw-bolder mb-0 view_details" style="padding-bottom: 6%;">View Details</h3></a> -->
						<div style="font-size: 13px;height:55px;overflow: auto" id="description">
							{!! $wv->integration_description !!}
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	@endforeach
</div>
@if ($integration)
{{-- start avaoid domain level restriction to access route --}}
	@php $hostBaseUrl = Request::getScheme().'://'.Request::getHost(); @endphp
 	@if($hostBaseUrl == 'https://esb.apiworx.net' || $hostBaseUrl=='https://esb-stag.apiworx.net')
		{{ $integration->withPath('/integration/integrations') }}
	@else
		{{ $integration->withPath('/integrations') }}
	@endif
 {{-- end avaoid domain level restriction to access route --}}
@endif
</div>



<div class="modal fade text-start modal-primary" id="primary" tabindex="-1" aria-labelledby="myModalLabel160"
	aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Integration Name</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">

				<div class="mb-1">
					<label class="my-label">Enter a name for this integration</label>
					<input type="text" class="form-control" id="flow_name" name="flow_name"
						placeholder="Integration Name">
					<span class="field_error">Field value is required</span>
				</div>
				<input type="hidden" class="form-control hide" id="flow_id" name="flow_id">
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light flow-name"
					data-bs-dismiss="modal">Save</button>
			</div>
		</div>
	</div>
</div>
<!-- Workflow Section -->
@endsection
@push('page-script')

<script src="{{ asset('public/js/jquery.jscroll.min.js')}}"></script>

<script type="text/javascript">

	$('ul.pagination').hide();
	let icon = $("#iconPath").val();
	let iconPath = icon + "/public/loading.gif";
	$(function () {
		$('.infinite-scroll').jscroll({
			autoTrigger: true,
			loadingHtml: `<img class="center-block scrollLoader" src="${iconPath}" alt="Loading..." />`,
			padding: 0,
			nextSelector: '.pagination li.active + li a',
			contentSelector: 'div.infinite-scroll',
			callback: function (nextSelector) {
				$('ul.pagination').remove();
				$(".sectionApplistBySearch").empty();
			}
		});
	});
</script>

<!-- select2 -->
<script src="{{asset('public/select2/js/script.js')}}"></script>
<!--end -->

<script>
	$(document).ready(function () {
		var tigger_integration_id = "{{$tigger_integration_id}}";
		if ($('.activate-flow[data-id="' + tigger_integration_id + '"]').length) {
			$('.activate-flow[data-id="' + tigger_integration_id + '"]').get(0).click();
		}
	});
	$(document.body).on('click', '.activate-flow', function () {
		let id = $(this).data('id');
		$('#flow_id').val(id);
		$('#flow_name').val('');
		$('#primary').modal('toggle');
	})

	$(document.body).on('click', '.btn-close', function () {
		$('#flow_name').val('');
		$('#primary').modal('toggle');
	})

	$(document.body).on('click', '.flow-name', function () {
		let id = $('#flow_id').val();
		let flow_name = $('#flow_name').val();
		//console.log(flow_name);
		if (flow_name.length) {
			$('#flow_name').next().hide();
			$.ajax({
				type: 'POST',
				url: "{{url('/connectWorkflow')}}",
				data: {
					'_token': $('meta[name="csrf-token"]').attr('content'),
					'id': id,
					'flow_name': flow_name
				},
				beforeSend: function () {
					showOverlay();
				},
				success: function (response) {
					hideOverlay();
					if (response.status_code === 1) {
						window.location.href = response.redirect_url;
					} else {
						toastr.error(response.status_text);
					}

				},
				error: function (jqXHR, textStatus, errorThrown) {
					hideOverlay();
					if (jqXHR.status == 500) {
						toastr.error('Internal error: ' + jqXHR.responseText);
					} else {
						toastr.error('Unexpected error Please try again.');
					}
				}
			});
		} else {
			$('#flow_name').next().show();
		}
	})
	$(document.body).on('click', '.view_details', function () {
		$("#description").toggle();
		$('.view_details').text(function (i, oldText) {
			return oldText === 'View Details' ? 'Hide Details' : 'View Details';
		});
	})
	$('.search_integration').on('search', function () {
		var t = $(this).val();
		loadApps(t);
	});
	function loadApps(t) {

		var csrf_token = $('meta[name="csrf-token"]').attr('content');
		var term = $(t).val();

		var regex = /(<([^>]+)>)/ig;
		term = term.replace(regex, "");
	
		var fixedUrl = $("#AjaxCallUrl").val();

		//start avaoid domain level restriction to access route
		if(fixedUrl == 'https://esb.apiworx.net' || fixedUrl == 'https://esb-stag.apiworx.net'){
			var getIntgUrl = fixedUrl +'/integration/integrations';
		}else{
			var getIntgUrl = fixedUrl +'/integrations';
		}
		//end avaoid domain level restriction to access route

		var contentServerPath = $("#contentServerPath").val();
		$.ajax({
			method: 'GET',
			//url: fixedUrl +'/integrations',
			url: getIntgUrl,
			// dataType: "json",
			data: {
				'term': (term) ? term : null,
				'_token': csrf_token,
			},
			success: function (data) {
				$modify = data.modify;
				let updateStatus = false;
				if (data.integration.data.length > 0) {
					let appListHtml = "";
					$.each(data.integration.data, function (k, v) {
						let actionButton = "";
						if ($modify == 1) {
							actionButton += `<span><button type="button" data-id="${v.integration_id}" class=" mb-1 btn btn-primary activate-flow waves-effect waves-float waves-light">Setup Integration</button></span>`;
						}

						appListHtml += `<div class="col-xl-4 col-md-4 col-12">
							<div class="card boxStyle1">
								<div class="card-header flex-column align-items-center pb-0">

									<div class="row justify-content-center align-items-center py-1 p-relative">
										<div class="connect-app"><span></span></div>
										<div class="col mb-xl-0 text-center">
											<div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
												<img class="icon" src="${contentServerPath}${v.p1_image}">
											</div>
										</div>
										<div class="col mb-xl-0 text-center">
											<div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
												<img class="icon" src="${contentServerPath}${v.p2_image}">
											</div>
										</div>
									</div>

									<h4 class="fw-bolder mt-1">${v.p1_name} + ${v.p2_name}</h4>
									<p class="card-text mb-1">
									
									</p>
									${actionButton}
								</div>
								<div class="card-body p-0">
									<div id="goal-overview-chart"></div>
									<div class="row border-top-blue text-center mx-0">

										<div class="col-12 py-1">
											<div style="font-size: 13px;height:55px;overflow: auto" id="description" >
												${v.integration_description}
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>`;
					})

					$(".sectionApplistBySearch").empty().html(appListHtml);


				} else {

					var appListHtml = `<div class="col-xl-12 col-md-12 col-12">
						<div class="card boxStyle1">
							<div class="card-body p-0">
								<div class="row">
									<div class="col-12 py-1">
										<h5 class="text-center">No Integration Found Like <span style="font-weight: bold;">#${data.searchVal}</span></h5>
									</div>
								</div>
							</div>
						</div>
					</div>`;

					$(".sectionApplistBySearch").empty().html(appListHtml);

				}


			}

		});

	}

	function activateFlowBySearch(id) {
		$('#flow_id').val(id);
		$('#flow_name').val('');
		$('#primary').modal('toggle');
	}

	$('#primary').on('hidden.bs.modal', function () {
		$(".search_integration").val(null).trigger("change");
		$('#flow_id').val("");
	})

	/* check users timezone in db if not found then set in storage , if current timezone not found in storage */
	$(document).ready(function () {

		let currentTimezone = null;
		if (localStorage.hasOwnProperty("current_time_zone")) {
			currentTimezone = localStorage.getItem("current_time_zone");
		}
		if (!currentTimezone) {
			$.ajax({
				type: 'GET',
				url: "{{url('/get_user_timezone')}}",
				data: {
					'_token': $('meta[name="csrf-token"]').attr('content')
				},
				success: function (response) {
					if (response.status_code === 1) {
						localStorage.removeItem("current_time_zone", +response.timezone);
					} else {
						//get timezone & store it to storage
						let newTimezone = getTimezone();
						if (newTimezone) {
							localStorage.setItem("current_time_zone", `${newTimezone}`);
						}
					}
				},
				error: function (jqXHR, textStatus, errorThrown) {
					if (jqXHR.status == 500) {
						toastr.error('Internal error: ' + jqXHR.responseText);
					} else {
						toastr.error('Unexpected error Please try again.');
					}
				}
			});
		}

	});

	function getTimezone() {
		var origin = Intl.DateTimeFormat().resolvedOptions().timeZone;
		let timezone_min = `${new Date().getTimezoneOffset()}`;
		let result = 0;
		let addsign = "";
		if (timezone_min.charAt(0) == "-") {
			result = timezone_min.replace("-", "");
			addsign = "+";
		} else {
			result = timezone_min.replace("+", "-");
			addsign = "-";
		}
		var hours = Math.floor(result / 60);
		var minutes = result % 60;
		var timezone = addsign + hours + ":" + minutes;

		return timezone;
	}

@if(request()->jwt)
localStorage.setItem("jwtToken", "{{ request()->jwt }}");
@endif
</script>



@endpush