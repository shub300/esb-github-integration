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

function connectMicroChip(){
    $('#mdlmicrochip_partner_identity').empty();
    $('#mdlMicroChip').modal('toggle'); 
    var data = new FormData($(".microchip_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectMicroChipAuth",
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
                retrievePlatformAccounts('microchip', 'destination');
				}else{
                errorNotify(res.status_text, 'Failed');
			}
		},
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
				}else{
                errorNotify('Unexpected error Please try again.','Failed');
			}
		}
	});
}

$(document.body).on('click', '#btnSubmitMicroChip', function(){
    var valid = true;
    $('.err_mgs').hide();
	
    if(!checkValue($('#microchip_partner_identity')))
    {
        showErrorAndFocus($('#microchip_partner_identity'));
        valid = false;
	}
	
    if(!checkValue($('#microchip_as2_url')))
    {
        showErrorAndFocus($('#microchip_as2_url'));
        valid = false;
	}
	
	if(!checkValue($('#microchip_encryption_certificate')))
    {
        showErrorAndFocus($('#microchip_encryption_certificate'));
        valid = false;
	}
	
	if(!valid)
    {
        return false;
	}
	
    var account_name = $('#microchip_partner_identity').val();
    $('#mdlmicrochip_partner_identity').html(account_name);
    $('#mdlMicroChip').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlmicrochip_partner_identity').empty();
    $('#mdlMicroChip').modal('toggle');
});