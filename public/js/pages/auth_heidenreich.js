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

function connectHeidenreich(){
    $('#mdlHeidenreich_cust_number').empty();
    $('#mdlHeidenreich_acc_username').empty();
    $('#mdlHeidenreich').modal('toggle');
    pdata = new FormData($(".heidenreich_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/connectHeidenreichAuth",
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
                retrievePlatformAccounts('heidenreich', 'destination');
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

$(document.body).on('click', '#btnSubmitHeidenreich', function() {
    valid = true;
    $('.err_mgs').hide();
    
    if(!checkvalue($('#heidenreich_customer_number'))){
        showErrorAndFocus($('#heidenreich_customer_number'));
        valid = false;
    }

    if(!checkvalue($('#heidenreich_user_name'))){
        showErrorAndFocus($('#heidenreich_user_name'));
        valid = false;
    }

    if(!checkvalue($('#heidenreich_secret'))){
        showErrorAndFocus($('#heidenreich_secret'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var heidenreich_acc_name = $('#heidenreich_customer_number').val();
    var heidenreich_user_name = $('#heidenreich_user_name').val();
    $('#mdlHeidenreich_cust_number').html(heidenreich_acc_name);
    $('#mdlHeidenreich_acc_username').html(heidenreich_user_name);
    $('#mdlHeidenreich').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlHeidenreich_cust_number').empty();
    $('#mdlHeidenreich_acc_username').empty();
    $('#mdlHeidenreich').modal('toggle');
});