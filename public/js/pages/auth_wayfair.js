var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem) {
    if (elem.length) {
        elem.next().show();
    }
}

function checkvalue(elem) {

    var input = elem.val();
	var regex = /(<([^>]+)>)/ig;
	var validatedInput = input.replace(regex, "");
	
	if(input != validatedInput){
		return false;
	}

    if (elem.length) {
        if (!elem.val().trim()) {
            return false;
        } else {
            return true;
        }
    }
}

function connectWayfair(){
    $('#mdlWayfair').modal('toggle');
    $('#mdlWayfair_acc_name').empty();
    $('#mdlWayfair_client_id').empty();

    pdata = new FormData($(".wayfair_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectWayfairOauth",
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
                retrievePlatformAccounts('wayfair', 'destination');
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

$(document.body).on('click', '#btnSubmitWayfair', function() {
    valid = true;
    $('.err_mgs').hide();
    if (!checkvalue($('#client_id'))) {
        showErrorAndFocus($('#client_id'));
        valid = false;
    }
    if (!checkvalue($('#client_secret'))) {
        showErrorAndFocus($('#client_secret'));
        valid = false;
    }
    if (!checkvalue($('#wayfair_account_name'))) {
        showErrorAndFocus($('#wayfair_account_name'));
        valid = false;
    }
    if (!checkvalue($('#env_type'))) {
        showErrorAndFocus($('#env_type'));
        valid = false;
    }
    if (!valid) {
        return false;
    }

    var wayfair_acc_name = $('#wayfair_account_name').val();
    var client_id = $('#client_id').val();
    $('#mdlWayfair_acc_name').html(wayfair_acc_name);
    $('#mdlWayfair_client_id').html(client_id);
    $('#mdlWayfair').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlWayfair_acc_name').empty();
    $('#mdlWayfair_client_id').empty();
    $('#mdlWayfair').modal('toggle');
});