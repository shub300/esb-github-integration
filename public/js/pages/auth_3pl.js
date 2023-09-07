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
$(document).ready(function() {
    $('.number_only').keyup(function(e)
    {
        if (/\D/g.test(this.value))
        {
        // Filter non-digits from input value.
        this.value = this.value.replace(/\D/g, '');
        }
    });
  });
function connect3pl(){

    $('#mdl3pl').modal('toggle');
    pdata = new FormData($(".3plform")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectThreePLAuth",
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
                retrievePlatformAccounts('3pl', 'destination');
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

$(document.body).on('click', '#btnSubmit3pl', function() {
    valid = true;
    $('.field_error').hide();

    if (!checkvalue($('#client_id'))) {
        showErrorAndFocus($('#client_id'));
        valid = false;
    }if(!checkvalue($('#client_secret'))) {
        showErrorAndFocus($('#client_secret'));
        valid = false;
    }if (!checkvalue($('#user_login_id'))) {
        showErrorAndFocus($('#user_login_id'));
        valid = false;
    } if (!checkvalue($('#tpl'))) {
        showErrorAndFocus($('#tpl'));
        valid = false;
    } if (!checkvalue($('#default_customer_id'))) {
        showErrorAndFocus($('#default_customer_id'));
        valid = false;
    } if (!checkvalue($('#default_facility_id'))) {
        showErrorAndFocus($('#default_facility_id'));
        valid = false;
    }if (!checkvalue($('#domain'))) {
        showErrorAndFocus($('#domain'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    $('#mdl3pl').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdl3pl').modal('toggle');
});
