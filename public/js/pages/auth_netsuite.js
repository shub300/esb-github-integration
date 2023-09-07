var baseUrl = $('body').attr('data-url');


function showErrorAndFocus(elem){
    if(elem.length){
        elem.next('.err_msg').show();
    }
}
function checkvalue(elem){

    var input = elem.val();
	var regex = /(<([^>]+)>)/ig;
	var validatedInput = input.replace(regex, "");

    if(input != validatedInput){
		return false;
	}

    if(elem.length){
        if(!elem.val().trim()){
            return false;
        }else{
            return true;
        }
    }
}



function connectNetsuite(){
    $('#mdl_ns_key').empty();
    $('#mdl_ns_acc_name').empty();
    $('#mdlNetsuite').modal('toggle');
    pdata = new FormData($(".netsuite_connect_form")[0]);
    showOverlay();
  //  $('#btnSubmitNetsuite').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectNetsuiteAuth",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
          //  $('#btnSubmitNetsuite').prop('disabled',false);
            if (res.status_code == "1") {
                successNotify(res.status_text,'Success');
                $('#p2_conn_section').hide();
                $('#p2_connection_form').empty();
                retrievePlatformAccounts('netsuite', 'destination');
            }
            else{
                errorNotify(res.status_text,'Failed');
            }
        },
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
            } else {
                errorNotify('Unexpected error Please try again.','Failed');
            }
        }
    });
}

$(document).ready(function()
{
    $('[data-toggle="tooltip"]').tooltip();


$(document).on('click', '#btnSubmitNetsuite', function() {
    valid = true;
    $('.err_msg').hide();
    $(['#netsuite_account_name','#ns_endpoint','#ns_host','#consumer_key','#consumer_secret','#ns_token','#ns_token_secret']).each(function(key,value){
  
    if(!checkvalue($(value))){
        showErrorAndFocus($(value));
        valid = false;
    }
    
    });
   
   
    if(!valid){
        return false;
    }

    var consumer_key = $('#consumer_key').val();
    var ns_account = $('#netsuite_account_name').val();
    $('#mdl_ns_key').html(consumer_key);
    $('#mdl_ns_acc_name').html(ns_account);
    $('#mdlNetsuite').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdl_ns_key').empty();
    $('#mdl_ns_acc_name').empty();
    $('#mdlNetsuite').modal('toggle');
});

});