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

function is_valid_url(url) {
    return /^(http(s)?:\/\/)?(www\.)?[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,5}(:[0-9]{1,5})?(\/.*)?$/.test(url);
}

function connectKlaviyo(){
    $('#mdlKlaviyo_account_name').empty();
    $('#mdlKlaviyo_public_key').empty();
    $('#mdlKlaviyo_private_key').empty();
    $('#mdlKlaviyo').modal('toggle');
    pdata = new FormData($(".klaviyo_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectKlaviyo",
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
                $('#p2_conn_section').hide();
                $('#p2_connection_form').empty();
                retrievePlatformAccounts('klaviyo', 'destination');
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

$(document.body).on('click', '#btnSubmitKlaviyo', function() {
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#klaviyo_account_name'))){
        showErrorAndFocus($('#klaviyo_account_name'));
        valid = false;
    }

    if(!checkvalue($('#klaviyo_public_key'))){
        showErrorAndFocus($('#klaviyo_public_key'));
        valid = false;
    }
    if(!checkvalue($('#klaviyo_private_key'))){
        showErrorAndFocus($('#klaviyo_private_key'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var klaviyo_account_name = $('#klaviyo_account_name').val();
    var klaviyo_public_key = $('#klaviyo_public_key').val();
    var klaviyo_private_key = $('#klaviyo_private_key').val();
    $('#mdlKlaviyo_account_name').html(klaviyo_account_name);
    $('#mdlKlaviyo_public_key').html(klaviyo_public_key);
    $('#mdlKlaviyo_private_key').html(klaviyo_private_key);
    $('#mdlKlaviyo').modal('toggle');

});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlKlaviyo_account_name').empty();
    $('#mdlKlaviyo_public_key').empty();
    $('#mdlKlaviyo_private_key').empty();
    $('#mdlKlaviyo').modal('toggle');
});