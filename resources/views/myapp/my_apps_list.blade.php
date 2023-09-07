@extends('layouts.master')

@section('head-content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>

</style>

@endsection

@section('title', 'Active Integrations')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Active Integrations</li>
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
	.delUserInteg {
		font-size:25px !important;
		position: absolute;
		right: 0px;
		padding:10px;
		cursor: pointer;
		z-index: 1;
	}
	.scrollLoader {
		position: absolute;
		right: 50%;
		bottom:0;
		z-index: 1;
		height:100px;
	}
	.jscroll-inner {
		display: flex;
    	flex-wrap: wrap;
		width:100%;
	}
	.jscroll-added > .infinite-scroll > .col-md-12 {
		padding:0px 25px 10px 25px;;
	}
	.jscroll-added
	{
		width:100%;
	}
	.jconfirm-content
	{
		text-align:justify;
	}
	.IntegrationSearchIcon{
		font-size: 20px !important;
		background-color: #2C6FA8 !important;
		padding: 9px !important;
		color: white !important;
	}
	.secondary-btn-style:focus{
           background-color: {{ isset($org_style->secondary_button_color) ? '#FFFFFF'.' !important': '' }};
    }
    
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<!-- <link rel="stylesheet" type="text/css" href="{{asset('public/css/select2@4.0.13/dist/css/select2.min.css')}}"> -->
<link rel="stylesheet" type="text/css" href="{{asset('public/select2/css/select2-bootstrap4.css')}}">


@endpush

@section('page-content')

<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">

@if($modify == 1)
<div class="row" style="display:flex;justify-content: right;margin-top: -40px !important;">
{{-- <div class="col-md-1" style="max-width: 60px;">
	<i class="fa fa-search IntegrationSearchIcon" aria-hidden="true"></i>
</div> --}}
<div class="col-md-4 col-xs-12" style="margin-right: 15px;
    margin-left: 27px;
    padding-left: 0px;">
{{-- <select class="search_integration">
</select> --}}
<input type="search" class="form-control ds-input search_integration"  placeholder="Search for active integrations..." aria-label="Search for..." autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-owns="algolia-autocomplete-listbox-0" dir="auto" style="position: relative; vertical-align: top;" onkeyup="loadApps(this)">

</div>
</div>
<br>
@endif



<div class="row infinite-scroll">
	@if(count($integration) < 1) <div class="col-xl-12 col-md-12 col-12">
		<div class="card boxStyle1">
			<div class="card-header flex-column align-items-center pb-0" style="height:350px;justify-content:center">
				<div class="alert alert-warning" role="alert" style="padding:10px !important">
					No app connected !
				</div>
				<a href="{{url('/integrations')}}"><img src="{{ asset('public/esb_asset/icons/plus-circle.svg') }}" alt="icon" width="50" height="50">
					<p>Connect your first App Now</p>
				</a>
				<p>
					<p>
						<a href="{{url('/integrations')}}" class="btn btn-primary">Go to Integrations</a>
				</div>
			</div>
		</div>
	@endif


@if(count($integration) > 0)
<div class="col-md-12 sectionApplist" style="display: flex !important;flex-wrap: wrap !important;">
@foreach ($integration as $item)
<div class="col-xl-4 col-md-4 col-12">
	<div class="card boxStyle1">
	@if ($item->ui_workflow_status !="active" && $modify == 1)
	<i class="fa fa-times-circle delUserInteg" onClick="delUserInteg({{$item->usrIntegId}},'{{ucfirst($item->source)}}','{{ucfirst($item->destination)}}')" aria-hidden="true" data-toggle="tooltip" data-placement="top" title="Delete this inactive integration"></i>
	@endif
		<div class="card-header flex-column align-items-center pb-0">

			<div class="row justify-content-center align-items-center py-1 p-relative">
				<div class="connect-app"><span></span></div>
				<div class="col mb-xl-0 text-center">
					<div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
						<img class="icon" src="{{env('CONTENT_SERVER_PATH').$item->sourceImg}}">
					</div>
				</div>
				<div class="col mb-xl-0 text-center">
					<div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
						<img class="icon" src="{{env('CONTENT_SERVER_PATH').$item->destinationImg}}">
					</div>
				</div>
			</div>

			<h4 class="fw-bolder mt-1">{{ucfirst($item->flow_name)}}</h4>
			<p class="card-text mb-1">
				<div style="display: flex;flex-direction:row;font-size:15px;">{{ucfirst($item->sourcePltName)}}&nbsp;&nbsp;<img src="{{asset('public/esb_asset/icons/repeat.svg')}}" alt="icon" width="20" height="20">&nbsp;&nbsp;{{ucfirst($item->destinationPltName)}}</div>
			</p>
			<p>
				@if($item->ui_workflow_status=="active")<span class="badge rounded-pill bg-success" style="padding:8px">{{ucfirst($item->ui_workflow_status)}}</span>
				@else
				<span class="badge rounded-pill bg-secondary" style="padding:8px">{{ucfirst($item->ui_workflow_status)}}</span>
				@endif
			</p>
		</div>
		<div class="card-body p-0">
			<div id="goal-overview-chart"></div>
			<div class="row border-top-blue text-center mx-0">

				<div style="padding:10px;justify-content:center;width:100%">
					<h6><span class="text-secondary setup-integration-txt">Total Flow : {{$item->flowCount}}</span>&nbsp;&nbsp;|&nbsp;&nbsp;<span class="text-success">Active Flow : {{$item->ActiveflowCount}}</span></h6>
				</div>

				<div class="col-12 py-1" style="padding-top:0px !important">
					@if ($item->ui_workflow_status=="active")
					<span><a href="{{url('/integration_flow/'.$item->usrIntegId)}}" class="btn btn-success btn-md btn-block secondary-btn-style">View Details</a></span>
					@else
						@if ($modify == 1)
						<span><a href="{{url('/connection-settings/'.$item->usrIntegId)}}" class="btn btn-primary btn-md btn-block">Connect Now</a></span>
						@else
						<span><a href="javascript:void(0)" class="btn btn-secondary btn-md btn-block" style="pointer-events: none;">Connect Now</a></span>
						@endif
					@endif
				</div>
			</div>
		</div>
	</div>
</div>
@endforeach
</div>
<br>
{{-- {{ $integration->links() }} --}}
@if($integration)
{{-- start avaoid domain level restriction to access route --}}
	@php $hostBaseUrl = Request::getScheme().'://'.Request::getHost(); @endphp
 	@if($hostBaseUrl == 'https://esb.apiworx.net' || $hostBaseUrl=='https://esb-stag.apiworx.net')
		{{ $integration->withPath('/integration/myapps') }}
	@else
		{{ $integration->withPath('/myapps') }}
	@endif
 {{-- end avaoid domain level restriction to access route --}}
@endif
@endif

</div>

@endsection


@push('page-script')


<!-- select2 -->
<script src="{{asset('public/select2/js/script.js')}}"></script>
<!--end -->

<script src="{{ asset('public/js/jquery.jscroll.min.js')}}"></script>
<script type="text/javascript">
        $('ul.pagination').hide();
		let icon = $("#iconPath").val();
		let iconPath = icon+"/public/loading.gif";
        $(function() {
            $('.infinite-scroll').jscroll({
                autoTrigger: true,
                loadingHtml: `<img class="center-block scrollLoader" src="${iconPath}" alt="Loading..." />`, 
                padding: 0,
                nextSelector: '.pagination li.active + li a',
                contentSelector: 'div.infinite-scroll',
                callback: function() {
                    $('ul.pagination').remove();
					$('[data-toggle="tooltip"]').tooltip();
                }
            });
			
        });
</script>

<script src="{{ asset('public/js/jquery-confirm/3.3.4/jquery-confirm.min.js')}}"></script>
<script>
function delUserInteg(userIntegId,source,dest)
{
	var csrf_token = $('meta[name="csrf-token"]').attr('content');
	let msg = `This is an inactive ${source} <-> ${dest} integration and not currently connected. Are you sure you want to remove this integration?`;
	confirmation("Confirm!",msg,userIntegId,csrf_token)
}
function confirmation(title,content,userIntegId,csrf_token){
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',
        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function () {
                    $.ajax({
                        type: 'POST',
						url: "{{url('delete_user_integration')}}",
                        data: { userIntegId,'_token': csrf_token },
                        success: function (data) {
                            if (data.status_code == 1) {
                                toastr.success(data.status_text);
								window.location.reload();
                            }
                            else
                            {
                                toastr.error(data.status_text);
                            }
                        }
                    });
                }
            },
            cancel: function () {
                //console.log('skip it');
            },
        }
    });
}
$('.search_integration').on('search',function(){
	var t =$(this).val();
	loadApps(t);
});
function loadApps(t){
	var csrf_token = $('meta[name="csrf-token"]').attr('content');
	let term = $(t).val();
	
	var regex = /(<([^>]+)>)/ig;
	term = term.replace(regex, "");

	var fixedUrl = $("#AjaxCallUrl").val();
	var contentServerPath = $("#contentServerPath").val();
	let iconPath = "{{asset('public/esb_asset/icons/repeat.svg')}}";
	$.ajax({
        method: 'GET',
        url: fixedUrl +'/myapps',
        // dataType: "json",
        data: {
			'term': (term) ? term : null,
            '_token': csrf_token,
        },
        success: function (data) {
			
            let appListHtml = "";
			$modify = data.modify;
			if( data.integration.data.length > 0){
				$.each(data.integration.data, function (k, v) {
						let deleteIntegBtn = "";
						let ui_workflow_statusBtn = "";
						let redirectBtn = "";

						if(v.ui_workflow_status=="active")
						{
							redirectBtn +=`<span><a href="${fixedUrl}/integration_flow/${v.usrIntegId}" class="btn btn-success btn-md btn-block">View Details</a></span>`;
							ui_workflow_statusBtn +=`<span class="badge rounded-pill bg-success" style="padding:8px">${v.ui_workflow_status}</span>`;
						} else {
							if ($modify == 1){
								redirectBtn +=`<span><a href="${fixedUrl}/connection-settings/${v.usrIntegId}" class="btn btn-primary btn-md btn-block">Connect Now</a></span>`;

								deleteIntegBtn +=`<i class="fa fa-times-circle delUserInteg" onClick="delUserInteg(${v.usrIntegId},'${v.source}','${v.destination}')" aria-hidden="true" data-toggle="tooltip" data-placement="top" title="Delete this inactive integration"></i>`;

							} else {
								redirectBtn +=`<span><a href="javascript:void(0)" class="btn btn-secondary btn-md btn-block" style="pointer-events: none;">Connect Now</a></span>`;
							}

							ui_workflow_statusBtn +=`<span class="badge rounded-pill bg-secondary" style="padding:8px">${v.ui_workflow_status}</span>`;

						}
						appListHtml += `<div class="col-xl-4 col-md-4 col-12">
						<div class="card boxStyle1">
							${deleteIntegBtn}
							<div class="card-header flex-column align-items-center pb-0">

								<div class="row justify-content-center align-items-center py-1 p-relative">
									<div class="connect-app"><span></span></div>
									<div class="col mb-xl-0 text-center">
										<div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
											<img class="icon" src="${contentServerPath}${v.sourceImg}">
										</div>
									</div>
									<div class="col mb-xl-0 text-center">
										<div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
											<img class="icon" src="${contentServerPath}${v.destinationImg}">
										</div>
									</div>
								</div>
								
								<h4 class="fw-bolder mt-1">${v.flow_name.charAt(0).toUpperCase() + v.flow_name.slice(1)}</h4>
								<p class="card-text mb-1">
									<div style="display: flex;flex-direction:row;font-size:15px;">${v.sourcePltName}&nbsp;&nbsp;<img src="${iconPath}" alt="icon" width="20" height="20">&nbsp;&nbsp;${v.destinationPltName}</div>
								</p>
								<p>
									${ui_workflow_statusBtn}
								</p>
							</div>
							<div class="card-body p-0">
								<div id="goal-overview-chart"></div>
								<div class="row border-top-blue text-center mx-0">

									<div style="padding:10px;justify-content:center;width:100%">
										<h6><span class="text-secondary">Total Flow : ${v.flowCount}</span>&nbsp;&nbsp;|&nbsp;&nbsp;<span class="text-success">Active Flow : ${v.ActiveflowCount}</span></h6>
									</div>

									<div class="col-12 py-1" style="padding-top:0px !important">
										${redirectBtn}
									</div>
								</div>
							</div>
							</div>
						</div>`;
				})
			} else {
					appListHtml +=`<div class="col-xl-12 col-md-12 col-12"><div class="card boxStyle1">
					<div class="card-header flex-column align-items-center pb-0" style="height:350px;justify-content:center">
						<div class="alert alert-warning esb-alert-warning" role="alert" style="padding:10px !important">
							No app Found ! like <span style="font-weight: bold;">#${data.searchVal}</span>
						</div>
						<a href="${fixedUrl}/integrations"><img src="{{ asset('public/esb_asset/icons/plus-circle.svg') }}" alt="icon" width="50" height="50">
							<p>Connect your App Now</p>
						</a>
						<p>
							<p>
								<a href="${fixedUrl}/integrations" class="btn btn-primary primary-btn-style">Go to Integrations</a>
						</div>
					</div>
					</div></div>`;
			}
			$(".sectionApplist").html(appListHtml);		
				

        }
    });
}
</script>

@endpush