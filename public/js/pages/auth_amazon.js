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

function connectAmazon(){
    $('#mdlAmazon_acc_name').empty();
    $('#mdlAmazon').modal('toggle');
    pdata = new FormData($(".amazon_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectAmazonBasicAuth",
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
                retrievePlatformAccounts('amazon', 'source');
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

$(document.body).on('click', '#btnSubmitAmazon', function() {
    valid = true;
    $('.err_mgs').hide();

    if(!checkvalue($('#amazon_account_name'))){
        showErrorAndFocus($('#amazon_account_name'));
        valid = false;
    }
    if(!checkvalue($('#access_key'))){
        showErrorAndFocus($('#access_key'));
        valid = false;
    }
    if(!checkvalue($('#secret_key'))){
        showErrorAndFocus($('#secret_key'));
        valid = false;
    }
    if(!checkvalue($('#role_arn'))){
        showErrorAndFocus($('#role_arn'));
        valid = false;
    }
    if(!checkvalue($('#region'))){
        showErrorAndFocus($('#region'));
        valid = false;
    }
    if(!checkvalue($('#market_place_id'))){
        showErrorAndFocus($('#market_place_id'));
        valid = false;
    }
    if(!checkvalue($('#client_id'))){
        showErrorAndFocus($('#client_id'));
        valid = false;
    }
    if(!checkvalue($('#client_secret'))){
        showErrorAndFocus($('#client_secret'));
        valid = false;
    }
    if(!checkvalue($('#refresh_token'))){
        showErrorAndFocus($('#refresh_token'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var amazon_acc_name = $('#amazon_account_name').val();

    $('#mdlAmazon_acc_name').html(amazon_acc_name);
    $('#mdlAmazon').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlAmazon_acc_name').empty();
    $('#mdlAmazon').modal('toggle');
});
