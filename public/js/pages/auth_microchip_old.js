var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem){
    if(elem.length){
        elem.next().show();
	}
}

function checkValue(elem){
    if(elem.length){
        if(!elem.val().trim()){
            return false;
			}else{
            return true;
		}
	}
}

function connectMicroChip(){
    $('#mdlmicrochip_ftp_domain').empty();
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
    if(!checkValue($('#microchip_ftp_domain')))
    {
        showErrorAndFocus($('#microchip_ftp_domain'));
        valid = false;
	}
	
    if(!checkValue($('#microchip_ftp_username')))
    {
        showErrorAndFocus($('#microchip_ftp_username'));
        valid = false;
	}

    if(!checkValue($('#microchip_ftp_password')))
    {
        showErrorAndFocus($('#microchip_ftp_password'));
        valid = false;
	}
	
	if(!valid)
    {
        return false;
	}
	
    var account_name = $('#microchip_ftp_domain').val();
    $('#mdlmicrochip_ftp_domain').html(account_name);
    $('#mdlMicroChip').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlmicrochip_ftp_domain').empty();
    $('#mdlMicroChip').modal('toggle');
});