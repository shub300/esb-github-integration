var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem){
    if(elem.length){
        elem.next().show();
    }
}

function checkValue(elem){
    
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

function connectShipHero(){
    $('#mdlshiphero_account_name').empty();
    $('#mdlShipHero').modal('toggle'); 
    var data = new FormData($(".shiphero_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectShipHeroAuth",
        data: data,
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
                retrievePlatformAccounts('shiphero', 'destination');
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

$(document.body).on('click', '#btnSubmitShipHero', function(){
    var valid = true;
    $('.err_mgs').hide();
    if(!checkValue($('#shiphero_username')))
    {
        showErrorAndFocus($('#shiphero_username'));
        valid = false;
    }

    if(!checkValue($('#shiphero_password')))
    {
        showErrorAndFocus($('#shiphero_password'));
        valid = false;
    }

    if(!valid)
    {
        return false;
    }

    var account_name = $('#shiphero_username').val();
    $('#mdlshiphero_account_name').html(account_name);
    $('#mdlShipHero').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlshiphero_account_name').empty();
    $('#mdlShipHero').modal('toggle');
});