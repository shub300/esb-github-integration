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



function connectMagento(){
    $('#mdl_mg_token').empty();
    $('#mdl_mg_acc_name').empty();
    $('#mdlMagento').modal('toggle');
    pdata = new FormData($(".magento_connect_form")[0]);
    showOverlay();
  //  $('#btnSubmitNetsuite').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectMagentoAuth",
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
                retrievePlatformAccounts('magento', 'destination');
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


$(document).on('click', '#btnSubmitMagento', function() {
    valid = true;
    $('.err_msg').hide();
    $(['#account_name','#access_token','#mg_host']).each(function(key,value){
  
    if(!checkvalue($(value))){
        showErrorAndFocus($(value));
        valid = false;
    }
    
    });
   
   
    if(!valid){
        return false;
    }

    var access_token = $('#access_token').val();
    var account_name = $('#account_name').val();
    $('#mdl_mg_acc_name').html(account_name);
    $('#mdl_mg_token').html(access_token);
    $('#mdlMagento').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdl_mg_token').empty();
    $('#mdl_mg_acc_name').empty();
    $('#mdlMagento').modal('toggle');
});

});