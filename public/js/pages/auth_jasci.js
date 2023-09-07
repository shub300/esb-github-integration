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

function connectJasci(){
    $('#mdlJasci_cust_number').empty();
    $('#mdlJasci_acc_username').empty();
    $('#mdlJasci').modal('toggle');
    pdata = new FormData($(".Jasci_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/connectJasciAuth",
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
                retrievePlatformAccounts('Jasci', 'destination');
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

$(document.body).on('click', '#btnSubmitJasci', function() {
    valid = true;
    $('.err_mgs').hide();
    
    if(!checkvalue($('#Jasci_customer_number'))){
        showErrorAndFocus($('#Jasci_customer_number'));
        valid = false;
    }

    if(!checkvalue($('#Jasci_user_name'))){
        showErrorAndFocus($('#Jasci_user_name'));
        valid = false;
    }

    if(!checkvalue($('#Jasci_secret'))){
        showErrorAndFocus($('#Jasci_secret'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var Jasci_acc_name = $('#Jasci_customer_number').val();
    var Jasci_user_name = $('#Jasci_user_name').val();
    $('#mdlJasci_cust_number').html(Jasci_acc_name);
    $('#mdlJasci_acc_username').html(Jasci_user_name);
    $('#mdlJasci').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlJasci_cust_number').empty();
    $('#mdlJasci_acc_username').empty();
    $('#mdlJasci').modal('toggle');
});