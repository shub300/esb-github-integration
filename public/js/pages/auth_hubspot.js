var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem) {
    if (elem.length) {
        elem.next().show();
    }
}

function checkvalue(elem) {
    if (elem.length) {
        if (!elem.val().trim()) {
            return false;
        } else {
            return true;
        }
    }
}
$(document).ready(function() {
    $('.number_only').keyup(function(e) {
        if (/\D/g.test(this.value)) {
            // Filter non-digits from input value.
            this.value = this.value.replace(/\D/g, '');
        }
    });
});

function connectHubSpot() {

    console.log("Here");
    // $('#mdlHubSpot').modal('toggle');
    pdata = new FormData($(".HubSpotform")[0]);
    showOverlay();
    $('.connect-now').prop('disabled', true);
    $.ajax({
        url: baseUrl + "/ConnectHubSpot",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function(res) {
            hideOverlay();
            console.log(res);
            $('.connect-now').prop('disabled', false);
            if (res.status_code == "1") {
                successNotify(res.status_text, 'Success');
                $('#p2_conn_section').hide();
                $('#p2_connection_form').empty();
                retrievePlatformAccounts('hubspot', 'destination');
            } else {
                errorNotify(res.status_text, 'Failed');
            }
        },
        error: function(jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
            } else {
                errorNotify('Unexpected error Please try again.', 'Failed');
            }
        }
    });
}

$(document.body).on('click', '#btnSubmitHubSpot', function() {
    valid = true;
    $('.field_error').hide();

    if (!checkvalue($('#account_name'))) {
        showErrorAndFocus($('#account_name'));
        valid = false;
    }

    console.log("valid: " + valid);
    if (!valid) {
        return false;
    }

    connectHubSpot();
    // $('#mdlHubSpot').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    // $('#mdlHubSpot').modal('toggle');
});