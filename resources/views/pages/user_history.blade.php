@extends('layouts.master')

@section('head-content')

<style>
.main-menu .navbar-header {
	height: auto !important;
}
/* .btn-primary:focus, 
.btn-primary:active{
	background-color:{{ isset($org_style->primary_button_hover_color) ? $org_style->primary_button_hover_color.' !important' : ''}};
} */

</style>

<link rel="stylesheet" type="text/css" href="{{asset('public/css/adminlte.min.css')}}">

<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&amp;display=fallback">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- daterange picker -->
<link rel="stylesheet" href="{{ asset('public/plugins/daterangepicker/daterangepicker.css') }}">

<link rel="stylesheet" href="{{asset('public/plugins/select2/css/select2.min.css')}}">
<link rel="stylesheet" href="{{asset('public/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css')}}">

@endsection

@section('title', 'Activity Log')


@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Activity Log</li>
@endsection

@push('page-style')
<style>

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

	.content-wrapper {
		margin-left : 0px !important;
	}

	.history_p {
		font-size: 16px !important;
	}

	.integration_name_label {
		color : #a4a4e9;
		margin-left: 100px;
	}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<link rel="stylesheet" type="text/css" href="{{asset('public/select2/css/select2-bootstrap4.css')}}">


@endpush

@section('page-content')
<input type="hidden" value="{{ url('/')}}" id="AjaxCallUrl">
<input type="hidden" value="{{env('CONTENT_SERVER_PATH')}}" id="contentServerPath">



<div class="row" style="display:flex;justify-content: right;">
<div class="col-md-12">
<form method="POST" id="filter_history_form" action="{{ url('get_user_log') }}" style="display:flex;justify-content: right;">
<input type="hidden" value="" name="currentTimezone" class="currentTimezone">
@csrf
	@if( count($list_integrations) > 0) 
		<div class="col-md-3">
			<select id="list_user_integrations" class="form-control" name="user_integrationFilter">
				<option value="">All Integrations</option>
				@foreach ($list_integrations as $integration_row)
				@if( isset($user_integrationFilter) && $user_integrationFilter==$integration_row->id)
				<option value="{{$integration_row->id}}" selected>{{$integration_row->flow_name}}</option>
				@else
				<option value="{{$integration_row->id}}" >{{$integration_row->flow_name}}</option>
				@endif
				@endforeach
				</select>
		</div>
	@endif

	<div class="col-md-3">
	<!-- {{$date_filter !=NULL ? $date_filter :''}} -->
		<input type="text" class="form-control float-right" autocomplete="false" id="date" name="date_filter"  
		value="" placeholder="From-To Date" >
    </div>
    <div class="col-md-2">
        <button id="ok" type="button" class="btn waves-effect waves-light btn-success">Search</button>&nbsp;&nbsp;
        <button id="ok2" type="reset" class="btn btn-primary"><i class="fa fa-undo" aria-hidden="true"></i></button>
    </div>
</form>
</div>
</div>
<br>

<div class="row">
	<div class="col-md-12 sectionHistoryBySearch" style="display: flex !important;flex-wrap: wrap !important;">
	</div>
</div>

<div class="row infinite-scroll">
@if(count($integration) < 1)

<div class="alert alert-primary" role="alert" style="width:100%;margin-top:50px;text-align:center">
No history found!
</div>
@endif

<?php
$formated_integration_data = [];
foreach( $integration as $history ) {

	$platform_integration_id = $history->platform_integration_id;

	$old_data = "";
	$new_data = "";

	if($history->old_data) {

		$formated_old_data = json_decode($history->old_data,true);

		//mapping object name
		$mapping_object_name = null;
		
		//find mapping label mapping_object_name
		$object_actual_name = isset($formated_old_data['mapping_object_name']) ? $formated_old_data['mapping_object_name'] : '';

		//if rule exist & rule has platform work flow rule & label exist for object
		if(isset($formated_rules_data) && isset($formated_rules_data[$platform_integration_id]) && isset($formated_rules_data[$platform_integration_id][$object_actual_name])) {
			$mapping_object_name = $formated_rules_data[$platform_integration_id][$object_actual_name];
		} else if(isset($formated_old_data['mapping_object_display_name'])) {
			$mapping_object_name = $formated_old_data['mapping_object_display_name'];
		}

	
		$trigger_type = null;
		if(isset($formated_old_data['trigger_type'])) {
			$trigger_type = $formated_old_data['trigger_type'];
		}


		if($mapping_object_name) {

			//mapping values
			$selected_mapping_values = [];
			if( isset($formated_old_data['source_mapping_value']) ) {
				array_push($selected_mapping_values,$formated_old_data['source_mapping_value']);
			}
			if( isset($formated_old_data['destination_mapping_value']) ) {
				array_push($selected_mapping_values,$formated_old_data['destination_mapping_value']);
			}
			$formated_mapping_val = "";
			if($selected_mapping_values) {
				$formated_mapping_val = implode(" <-> ",$selected_mapping_values);
			}

			$old_data.='<strong>Mapping Field Name :</strong>'.$mapping_object_name."<br>";
			$old_data.='<strong>Mapping Values :</strong>'.$formated_mapping_val;
		} else if ($trigger_type){
			$description = $formated_old_data['description'];
			$old_data.='<strong>Action :</strong>'.$trigger_type."<br>";
			$old_data.='<strong>Description :</strong>'.$description;
		}
		

	}

	if($history->new_data) {

		$formated_new_data = json_decode($history->new_data,true);
		
		//mapping object name
		$mapping_object_name = null;

		//find mapping label mapping_object_name
		$object_actual_name = isset($formated_new_data['mapping_object_name']) ? $formated_new_data['mapping_object_name'] : '';

		//if rule exist & rule has platform work flow rule & label exist for object
		if(isset($formated_rules_data) && isset($formated_rules_data[$platform_integration_id]) && isset($formated_rules_data[$platform_integration_id][$object_actual_name])) {
			$mapping_object_name = $formated_rules_data[$platform_integration_id][$object_actual_name];
		} else if(isset($formated_new_data['mapping_object_display_name'])) {
			$mapping_object_name = $formated_new_data['mapping_object_display_name'];
		}
		

		if($mapping_object_name) {

			//mapping values
			$selected_mapping_values = [];
			if( isset($formated_new_data['source_mapping_value']) ) {
				array_push($selected_mapping_values,$formated_new_data['source_mapping_value']);
			}
			if( isset($formated_new_data['destination_mapping_value']) ) {
				array_push($selected_mapping_values,$formated_new_data['destination_mapping_value']);
			}
			$formated_mapping_val = "";
			if($selected_mapping_values) {
				$formated_mapping_val = implode(" <-> ",$selected_mapping_values);
			}

			$new_data.='<strong>Mapping Field Name :</strong>'.$mapping_object_name."<br>";
			$new_data.='<strong>Mapping Values :</strong>'.$formated_mapping_val;

		} else if ($trigger_type){
			$trigger_type = $formated_new_data['trigger_type'];
			$description = $formated_new_data['description'];
			$new_data.='<strong> Action :</strong>'.$trigger_type."<br>";
			$new_data.='<strong>Flow Name :</strong>'.$description;
		}
		

	}

	$current_action_date = date('Y-m-d', strtotime($history->updated_at));
	$current_action_time = date('H:i:s', strtotime($history->updated_at));
	
	//if current_action_date already exits
	if( isset($formated_integration_data) && isset($formated_integration_data[$current_action_date]) ) {
		//check time... 
		if ( isset($formated_integration_data[$current_action_date][$current_action_time]) ) {

			$data = [];
			$data['flow_name'] = $history->flow_name;
			$data['action'] = $history->action;
			$data['email'] = $history->email;
			$data['old_data'] = $old_data;
			$data['new_data'] = $new_data;
			$data['created_at'] = $history->created_at;
			$data['updated_at'] = $history->created_at;

			//push in array
			$formated_integration_data[$current_action_date][$current_action_time][] = $data;

		} else {
			
			$data['flow_name'] = $history->flow_name;
			$data['action'] = $history->action;
			$data['email'] = $history->email;
			$data['old_data'] = $old_data;
			$data['new_data'] = $new_data;
			$data['created_at'] = $history->created_at;
			$data['updated_at'] = $history->created_at;

			//push in array
			$formated_integration_data[$current_action_date][$current_action_time][] = $data;
		}

	} else {
		$data = [];
		$data['flow_name'] = $history->flow_name;
		$data['action'] = $history->action;
		$data['email'] = $history->email;
		$data['old_data'] = $old_data;
		$data['new_data'] = $new_data;
		$data['created_at'] = $history->created_at;
		$data['updated_at'] = $history->created_at;

		//push in array
		$formated_integration_data[$current_action_date][$current_action_time][] = $data;
	}
}
?>

<div class="col-md-12" style="display: flex !important;flex-wrap: wrap !important;">
	<div class="row" style="width:100% !important">
	<div class="col-md-12">
	<div class="timeline" style="width:100 !important;min-width:800px !important">

	@foreach($formated_integration_data as $index => $main_row)

	<!-- history date block -->
	<div class="time-label">
	<span class="bg-red">{{ $index }}</span>
	</div>
	<!-- history date block -->


		@foreach($main_row as $childIndex => $child_row)

		

			@foreach ( $child_row as $history)

			<?php
			if($history['action'] =="Mapping Added") {
				$list_icon_class = "fas fa-plus bg-blue";
			} else if($history['action'] =="Mapping Update") {
				$list_icon_class = "fas fa-pencil bg-blue";
			} else if($history['action'] =="Mapping Delete") {
				$list_icon_class = "fas fa-minus bg-blue";
			} else if($history['action'] =="Mapping Refresh") {
				$list_icon_class = "fas fa-refresh bg-blue";
			} else if($history['action'] =="Flow ON/OFF Trigger") {
				$list_icon_class = "fas fa-power-off bg-blue";
			} else if($history['action'] =="Account Disconnect") {
				$list_icon_class = "fas fa-plug bg-blue";
			} else if($history['action'] =="Resync Trigger") {
				$list_icon_class = "fas fa-refresh bg-blue";
			} else {
				$list_icon_class = "fas fa-eercast bg-blue";
			}

			
			?>


			@if( $history['action'] =="Mapping Added" || $history['action'] =="Mapping Update" || $history['action'] =="Mapping Delete" )
			<!-- history detail block-->
			<div>
			<i class="{{$list_icon_class}}"></i>
			<div class="timeline-item">
			<span class="time"><i class="fas fa-clock"></i> {{ $childIndex }}</span>
			
			<h3 class="timeline-header"><a href="#">{{ $history['action'] }}</a> By {{ $history['email'] }} <span class="integration_name_label"> ~ on {{ $history['flow_name'] }} Integration</span></h3>
			<div class="timeline-body">
			<p class="history_p">{!! $history['old_data'] !!}</p>
			<p class="history_p">{!! $history['new_data'] !!}</p>
			<!-- {{isset($history['old_data'])? ' ~ New Changes':''}} -->
			</div>
			<!-- <div class="timeline-footer">
			<a class="btn btn-primary btn-sm">Read more</a>
			<a class="btn btn-danger btn-sm">Delete</a>
			</div> -->
			</div>
			</div>
			<!-- history detail block-->
			@elseif( $history['action'] =="Flow ON/OFF Trigger")
			<!--  single live action block -->
			<div>
			<i class="{{$list_icon_class}}"></i>
			<div class="timeline-item">
			<span class="time"><i class="fas fa-clock"></i> {{ $childIndex }}</span>
			<h3 class="timeline-header no-border"><a href="#">{{ $history['action'] }}</a> By {{ $history['email'] }} <span class="integration_name_label"> ~ on {{ $history['flow_name'] }} Integration</span></h3>
			<div class="timeline-body">
			<p class="history_p">{!! $history['new_data'] !!}</p>
			</div>
			</div>
			</div>
			@else
			<div>
			<i class="{{$list_icon_class}}"></i>
			<div class="timeline-item">
			<span class="time"><i class="fas fa-clock"></i> {{ $childIndex }}</span>
			<h3 class="timeline-header no-border"><a href="#">{{ $history['action'] }}</a> By {{ $history['email'] }} <span class="integration_name_label"> ~ on {{ $history['flow_name'] }} Integration</span></h3>
			<!-- <div class="timeline-body">
			<p class="history_p">{!! $history['old_data'] !!}</p>
			</div> -->
			</div>
			</div>
			@endif


			@endforeach
			
		<!--  single live action block -->
		@endforeach

	

	@endforeach
	<!-- end date block -->
	<div>
	@if(count($integration) > 1)
	<!-- <i class="fas fa-clock bg-gray"></i> -->
	</div>
	<!-- end date block -->
	@endif

	</div>
	</div>
	</div>


</div>

@if ($integration)
{{-- start avaoid domain level restriction to access route --}}
	@php $hostBaseUrl = Request::getScheme().'://'.Request::getHost(); @endphp
 	@if($hostBaseUrl == 'https://esb.apiworx.net' || $hostBaseUrl=='https://esb-stag.apiworx.net')
		{{ $integration->withPath('/integration/get_user_log') }}
	@else
		{{ $integration->withPath('/get_user_log') }}
	@endif
 {{-- end avaoid domain level restriction to access route --}}
@endif

</div>


@endsection
@push('page-script')

<script src="{{ asset('public/js/jquery.jscroll.min.js')}}"></script>

<script type="text/javascript">

	$('ul.pagination').hide();
	let icon = $("#iconPath").val();
	let iconPath = icon + "/public/loading.gif";
	$(function () {
		let list_user_integrations = $("#list_user_integrations option:selected").val();
		$('.infinite-scroll').jscroll({
			autoTrigger: true,
			loadingHtml: `<img class="center-block scrollLoader" src="${iconPath}" alt="Loading..." />`,
			padding: 0,
			nextSelector: '.pagination li.active + li a',
			contentSelector: 'div.infinite-scroll',
			callback: function (nextSelector) {
				
				$('ul.pagination').remove();
				$(".sectionHistoryBySearch").empty();
			}
		});
	});
</script>


<script src="{{ asset('public/plugins/moment/moment.min.js') }}"></script>
<script src="{{ asset('public/plugins/daterangepicker/daterangepicker.js') }}"></script>

<script>

		$('#date').daterangepicker({
            autoUpdateInput: false,
            locale: {
                cancelLabel: 'Clear'
            },
        });

        $('#date').on('apply.daterangepicker', function (ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format(
                'YYYY-MM-DD'));
        });

        $('#date').on('cancel.daterangepicker', function (ev, picker) {
            $(this).val('');

        });		


	$('#list_user_integrations').change(function() {
		getCurrentTimeZone();
		$("#filter_history_form").submit()
    });

	$ok = $('#ok');
	var $ok2 = $('#ok2');
    $(function() {
		$ok.click(function() {
			$("#filter_history_form").submit()
        });
        $ok2.click(function() {
            $('#date').val('');
			$("#list_user_integrations option:selected").removeAttr("selected");
			getCurrentTimeZone();
			$("#filter_history_form").submit()
        });
       
    });

	function getCurrentTimeZone()
	{
		let currentTimezone = "+00:00";
		if (localStorage.hasOwnProperty("current_time_zone"))
        {
            currentTimezone = localStorage.getItem("current_time_zone");
		}
		$(".currentTimezone").val(currentTimezone);
	}

	$( document ).ready(function() {
		getCurrentTimeZone();
		$('#list_user_integrations').select2({
            theme: 'bootstrap4',
            width: $(this).data('width') ? $(this).data('width') : $(this).hasClass('w-100') ? '100%' : 'style',
            placeholder: $(this).data('placeholder'),
            allowClear: Boolean($(this).data('allow-clear')),
            closeOnSelect: !$(this).attr('multiple'),
        });
	})

</script>



@endpush