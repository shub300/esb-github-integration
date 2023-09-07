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

function connectTeapplix(){
    $('#mdlteapplix_account_name').empty();
    $('#mdlTeapplix').modal('toggle');
    pdata = new FormData($(".teapplix_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectTeapplixAuth",
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
                retrievePlatformAccounts('teapplix', 'source');
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

$(document.body).on('click', '#btnSubmitTeapplix', function(){
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#teapplix_account_name')))
    {
        showErrorAndFocus($('#teapplix_account_name'));
        valid = false;
    }

    if(!checkvalue($('#teapplix_api_token')))
    {
        showErrorAndFocus($('#teapplix_api_token'));
        valid = false;
    }

    if(!valid)
    {
        return false;
    }

    var account_name = $('#teapplix_account_name').val();
    $('#mdlteapplix_account_name').html(account_name);
    $('#mdlTeapplix').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlteapplix_account_name').empty();
    $('#mdlTeapplix').modal('toggle');
});