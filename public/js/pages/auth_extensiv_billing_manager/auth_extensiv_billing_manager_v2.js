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
    $('.number_only').keyup(function(e){
		if (/\D/g.test(this.value))
		{
			// Filter non-digits from input value.
			this.value = this.value.replace(/\D/g, '');
		}
	});
});

function connectExtensivBillingManagerAccount(){
    //$('#mdlExtensivBillingManager').modal('toggle');
    pdata = new FormData($(".extensiv_billing_manager_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectExtensivBillingManagerAccount",
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
                retrievePlatformAccounts('extensivbillingmanager', 'source');
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

$(document.body).on('click', '#btnSubmitExtensivBillingManagerAccount', function() {
    valid = true;
    $('.field_error').hide();
	
	if (!checkvalue($('#account_name'))) {
        showErrorAndFocus($('#account_name'));
        valid = false;
	}
	
	/*
	if (!checkvalue($('#client_id'))) {
        showErrorAndFocus($('#client_id'));
        valid = false;
	}
	
	if(!checkvalue($('#client_secret'))) {
        showErrorAndFocus($('#client_secret'));
        valid = false;
	}
	*/
	
    if(!valid){
        return false;
	}
	
    //$('#mdlExtensivBillingManager').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    //$('#mdlExtensivBillingManager').modal('toggle');
});
