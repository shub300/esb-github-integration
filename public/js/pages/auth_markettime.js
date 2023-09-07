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

function connectMarketTime(){
    $('#mdlMarkettime_acc_name').empty();
    $('#mdlMarkettime_acc_companyID').empty();
    $('#mdlMarkettime').modal('toggle');
    pdata = new FormData($(".markettime_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectMarkettimeAuth",
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
                retrievePlatformAccounts('Markettime', 'source');
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

$(document.body).on('click', '#btnSubmitmarkettime', function() {
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#markettime_account_name'))){
        showErrorAndFocus($('#markettime_account_name'));
        valid = false;
    }

    if(!checkvalue($('#markettime_companyID'))){
        showErrorAndFocus($('#markettime_companyID'));
        valid = false;
    }

    if(!checkvalue($('#markettime_api_key'))){
        showErrorAndFocus($('#markettime_api_key'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var markettime_companyID = $('#markettime_companyID').val();
    var markettime_account_name = $('#markettime_account_name').val();
    $('#mdlMarkettime_acc_companyID').html(markettime_companyID);
    $('#mdlMarkettime_acc_name').html(markettime_account_name);
    $('#mdlMarkettime').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlMarkettime_acc_companyID').empty();
    $('#mdlMarkettime_acc_name').empty();
    $('#mdlMarkettime').modal('toggle');
});