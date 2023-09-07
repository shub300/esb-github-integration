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

function connectZulily(){
    $('#mdlZulily_acc_name').empty();
    $('#mdlZulily').modal('toggle');
    pdata = new FormData($(".zulily_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectZulilytAuth",
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
                retrievePlatformAccounts('zulily', 'source');
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

$(document.body).on('click', '#btnSubmitZulily', function() {
    valid = true;
    $('.err_mgs').hide();

    if(!checkvalue($('#zulily_account_name'))){
        alert(showErrorAndFocus($('#zulily_account_name')));
        valid = false;
    }
    if(!checkvalue($('#api_key'))){
        showErrorAndFocus($('#api_key'));
        valid = false;
    }
    if(!checkvalue($('#vendor_id'))){
        showErrorAndFocus($('#vendor_id'));
        valid = false;
    }


    if(!valid){
        return false;
    }

    var zulily_acc_name = $('#zulily_account_name').val();

    $('#mdlZulily_acc_name').html(zulily_acc_name);
    $('#mdlZulily').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlZulily_acc_name').empty();
    $('#mdlZulily').modal('toggle');
});
