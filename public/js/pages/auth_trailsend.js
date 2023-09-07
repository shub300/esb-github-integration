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

function connectTrailsend(){

    $('#mdlTrailsend_cust_number').empty();
    $('#mdlTrailsend').modal('toggle');
    pdata = new FormData($(".trailsend_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/connectTrailsendAuth",
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
                retrievePlatformAccounts('trailsend', 'destination');
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

$(document.body).on('click', '#btnSubmitTrailsend', function() {
    valid = true;
    $('.err_mgs').hide();
    
    if(!checkvalue($('#trailsend_customer_number'))){
        showErrorAndFocus($('#trailsend_customer_number'));
        valid = false;
    }

    if(!checkvalue($('#trailsend_secret'))){
        showErrorAndFocus($('#trailsend_secret'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var trailsend_acc_name = $('#trailsend_customer_number').val();
    var trailsend_api_key = $('#trailsend_secret').val();

    $('#mdlTrailsend_cust_number').html(trailsend_acc_name);
    $('#mdlTrailsend_api_key').html(trailsend_api_key);
    $('#mdlTrailsend').modal('toggle');

});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlTrailsend_cust_number').empty();
    $('#mdlTrailsend_api_key').empty();
    $('#mdlTrailsend').modal('toggle');
});