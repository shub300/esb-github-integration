// Function to list of integration flows
var isVisible = true;
//use this to hide updated_at column for extensive  extensivbillingmanager/skubana
if( $("#log_event").find('option:selected').attr("data-sourcepltid")=="extensivbillingmanager" || $("#log_event").find('option:selected').attr("data-destpltid")=="extensivbillingmanager") {
    isVisible = false;
}
$(".datatables-auditLogs").DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        //url: fixedUrl+'/audit_log/'+userIntegId,
        url: $("#AjaxCallUrl").val()+'/audit_log',
        type: 'post',
        'data': function(data){
            $("#resyncRecordIds").val();
            var date = $("#date").val();
            //get timezone data from storage
            let currentTimezone = "+00:00";
            if (localStorage.hasOwnProperty("current_time_zone"))
            {
                currentTimezone = localStorage.getItem("current_time_zone");
			}
            if(date){
                var dateArr = date.split(' - ');
                data.from_date = formatDate(dateArr[0]);
                data.to_date = formatDate(dateArr[1]);
			}
            else{
                data.from_date = '';
                data.to_date = '';
			}

    
            data.event_name = $("#log_event").val();
            data.uwfrid = $("#log_event").find('option:selected').attr("data-uwfrid");
            data.FilterByDate = $("#FilterByDate").find('option:selected').val();
            data.status = $("#status").val();
            data.user_intg_id = $('#input_IntegPlateformId').val();
            data._token = $('meta[name="csrf-token"]').attr('content');
            data.sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");   
            data.destPlatformId = $("#log_event").find('option:selected').attr("data-destplt"); 
            data.arrayIntegEvents = $('#arrayIntegEvents').val();  
            data.currentTimezone = currentTimezone;   
		},
	},
    responsive: true,
	"fnInitComplete": function (oSettings, json) {
        $('[data-toggle="tooltip"]').tooltip();
	},
    "fnInitComplete": function (oSettings, json) {
        $('body').tooltip({selector: '[data-toggle="tooltip"]'});
	},
    columns: [
        {data: 'intg_platform', name: 'intg_platform', 'visible': true, 'orderable': false},
        {data: 'info', name: 'info', 'visible': true, 'orderable': true},
        {data: 'destination_reference', name: 'destination_reference', 'visible': true, 'orderable': true},
        {data: 'synced_at', name: 'synced_at', 'visible': true, 'orderable': true},
        {data: 'type', name: 'type', 'visible': true, 'orderable': false},
        {data: 'status', name: 'status', 'visible': true, 'orderable': false},
        {data: 'last_run', name: 'last_run', 'visible': isVisible, 'orderable': true},
        {data: 'action', name: 'action', 'visible': true, 'orderable': false},
	],
    order: [[6, 'desc']],
    columnDefs: [
        {
            responsivePriority: 1,
            targets: 0
		},
        {
            responsivePriority: 2,
            targets: -1
		}
	],
});

//resync All failed
$(document).on('click','#btn-resync-all',function(){
    $userIntegId = $("#input_IntegPlateformId").val();
    $logType = $("#log_event").val();
    $logTypeName = $("#log_event option:selected").text();
    $sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");    
    $destPlatformId = $("#log_event").find('option:selected').attr("data-destplt");
    
    send_data = {
        'resync_all': "Yes",
        'userIntegId':$userIntegId,
        'logType':$logType,
        'logTypeName':$logTypeName,
        "_token": $('meta[name="csrf-token"]').attr('content'),
        'sourcePlatformId' : $sourcePlatformId,
        'destPlatformId' : $destPlatformId
	};
    showOverlay();
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/resync_platform_data',
        data: send_data,
        dataType: "json",
        success: function(res) {
            hideOverlay();
            if(res.status_code==1){
                $(".datatables-auditLogs").DataTable().ajax.reload();
                successNotify(res.status_text,'Success');
				}else if(res.status_code==0){
                errorNotify(res.status_text,'Failed');
			}
		},
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
				} else {
                errorNotify('Unexpected error Please try again.', 'Failed');
			}
		}
	});
});

//resync single failed
$(document).on('click','.btn-resync',function(){
    $id = $(this).data('id');
    $user_id = $(this).data('user_id');
    $user_integration_id = $(this).data('user_integration_id');
    $source_platform_id = $(this).data('source_platform_id');
    $dest_platform_id = $(this).data('dest_platform_id');
    $user_wf_rule_id = $(this).data('user_wf_rule_id');
    $pf_wf_rule_id = $(this).data('pf_wf_rule_id');
    $sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");   
    $destPlatformId = $("#log_event").find('option:selected').attr("data-destplt");
    send_data = {
        'resync_all': "No",
        'id':$id,
        'user_id':$user_id,
        'user_integration_id':$user_integration_id,
        'source_platform_id':$source_platform_id,
        'dest_platform_id':$dest_platform_id,
        'user_wf_rule_id':$user_wf_rule_id,
        'pf_wf_rule_id':$pf_wf_rule_id,
        "_token": $('meta[name="csrf-token"]').attr('content'),
        'sourcePlatformId' : $sourcePlatformId,
        'destPlatformId' : $destPlatformId
	};
    showOverlay();
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/resync_platform_data',
        data: send_data,
        dataType: "json",
        success: function(res) {
            hideOverlay();
            if(res.status_code==1){
                $(".datatables-auditLogs").DataTable().ajax.reload();
                successNotify(res.status_text,'Success');
				}else if(res.status_code==0){
                errorNotify(res.status_text,'Failed');
			}
		},
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
				} else {
                errorNotify('Unexpected error Please try again.', 'Failed');
			}
		}
	});
});

//ignore single failed
$(document).on('click','.btn-ignore',function(){
    $id = $(this).data('id');
    $user_id = $(this).data('user_id');
    $user_integration_id = $(this).data('user_integration_id');
    $log_event = $("#log_event").val();
    $sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");   
    $destPlatformId = $("#log_event").find('option:selected').attr("data-destplt");
	
    send_data = {
        'resync_all': "Ignore",
        'id':$id,
        'user_id':$user_id,
        'user_integration_id':$user_integration_id,
        "_token": $('meta[name="csrf-token"]').attr('content'),
        "log_event" : $log_event,
        'sourcePlatformId' : $sourcePlatformId,
        'destPlatformId' : $destPlatformId
	};
    showOverlay();
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/resync_platform_data',
        data: send_data,
        dataType: "json",
        success: function(res) {
            hideOverlay();
            if(res.status_code==1){
                $(".datatables-auditLogs").DataTable().ajax.reload();
                successNotify(res.status_text,'Success');
				}else if(res.status_code==0){
                errorNotify(res.status_text,'Failed');
			}
		},
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
				} else {
                errorNotify('Unexpected error Please try again.', 'Failed');
			}
		}
	});
});

//set failed single ignore
$(document).on('click','.btn-failed',function(){
    $id = $(this).data('id');
    $user_id = $(this).data('user_id');
    $user_integration_id = $(this).data('user_integration_id');
    $log_event = $("#log_event").val();
    $sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");     
    $destPlatformId = $("#log_event").find('option:selected').attr("data-destplt");
	
    send_data = {
        'resync_all': "Failed",
        'id':$id,
        'user_id':$user_id,
        'user_integration_id':$user_integration_id,
        "_token": $('meta[name="csrf-token"]').attr('content'),
        "log_event" : $log_event,
        'sourcePlatformId' : $sourcePlatformId,
        'destPlatformId' : $destPlatformId
	};
    showOverlay();
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/resync_platform_data',
        data: send_data,
        dataType: "json",
        success: function(res) {
            hideOverlay();
            if(res.status_code==1){
                $(".datatables-auditLogs").DataTable().ajax.reload();
                successNotify(res.status_text,'Success');
				}else if(res.status_code==0){
                errorNotify(res.status_text,'Failed');
			}
		},
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
				} else {
                errorNotify('Unexpected error Please try again.', 'Failed');
			}
		}
	});
});

function formatDate(date) {
    var d = new Date(date),
	month = '' + (d.getMonth() + 1),
	day = '' + d.getDate(),
	year = d.getFullYear();
	
    if (month.length < 2)
	month = '0' + month;
    if (day.length < 2)
	day = '0' + day;
	
    return [year, month, day].join('-');
}


//get details
$(document).on('click','.btn-getDetail',function(){
    $id = $(this).data('id');
    $user_id = $(this).data('user_id');
    $user_integration_id = $(this).data('user_integration_id');
    $log_event = $("#log_event").val();
    $sourcePlatformId = $("#log_event").find('option:selected').attr("data-sourceplt");   
    $destPlatformId = $("#log_event").find('option:selected').attr("data-destplt");
   
    $sourcePlaName = $("#log_event").find('option:selected').attr("data-sourcepltid");
    $destPltName = $("#log_event").find('option:selected').attr("data-destpltid");
	
    send_data = {
        'id':$id,
        'user_id':$user_id,
        'user_integration_id':$user_integration_id,
        "_token": $('meta[name="csrf-token"]').attr('content'),
        "log_event" : $log_event,
        'sourcePlatformId' : $sourcePlatformId,
        'destPlatformId' : $destPlatformId,
        'sourcePlaName' : $sourcePlaName,
        'destPltName' : $destPltName
	};
    showOverlay();
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/get_log_details',
        data: send_data,
        dataType: "json",
        success: function(res) {
            //update modal title
            if(res.title) {
                $("#logDetailModalTitle").html(res.title);
            }
            //update modal body
            $(".logDetailModalBody").html(res.body);
            
            hideOverlay();

            //show modal
            $("#logDetailModal").modal('show');

		},
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
				} else {
                errorNotify('Unexpected error Please try again.', 'Failed');
			}
		}
	});
});