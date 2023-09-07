var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem){
    if(elem.length){
        elem.next().show();
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

function connectSkuvault(){
    $('#mdlSkuvault_acc_name').empty();
    $('#mdlSkuvault_acc_email').empty();
    $('#mdlSkuvault').modal('toggle');
    pdata = new FormData($(".skuvault_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectSkuvaultAuth",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
            $('.connect-now').prop('disabled',false);
            if (res.status_code == "1") {
                successNotify(res.status_text,'Success');
                $('#p1_conn_section').hide();
                $('#p1_connection_form').empty();
                retrievePlatformAccounts('skuvault', 'source');
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

$(document.body).on('click', '#btnSubmitSkuvault', function() {
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#skuvault_email'))){
        showErrorAndFocus($('#skuvault_email'));
        valid = false;
    }else {
      //check valid email
      var mailformat = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/;
      if(!$('#skuvault_email').val().match(mailformat))
      {
        $('.email_error').html('Invalid Email format.');
        showErrorAndFocus($('#skuvault_email'));
        valid = false;
      }
    }
    if(!checkvalue($('#skuvault_password'))){
        showErrorAndFocus($('#skuvault_password'));
        valid = false;
    }

    if(!checkvalue($('#skuvault_account_name'))){
        showErrorAndFocus($('#skuvault_account_name'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var skuvault_acc_name = $('#skuvault_account_name').val();
    var skuvault_email = $('#skuvault_email').val();
    $('#mdlSkuvault_acc_name').html(skuvault_acc_name);
    $('#mdlSkuvault_acc_email').html(skuvault_email);
    $('#mdlSkuvault').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlSkuvault_acc_name').empty();
    $('#mdlSkuvault_acc_email').empty();
    $('#mdlSkuvault').modal('toggle');
});