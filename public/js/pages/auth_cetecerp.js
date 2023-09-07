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

function connectCetecERP(){
    $('#mdlcetecerp_ftp_domain').empty();
    $('#mdlCetecERP').modal('toggle'); 
    var data = new FormData($(".cetecerp_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectCetecERPAuth",
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
                $('#p1_conn_section').hide();
                $('#p1_connection_form').empty();
                retrievePlatformAccounts('cetecerp', 'source');
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

$(document.body).on('click', '#btnSubmitCetecERP', function(){
    var valid = true;
    $('.err_mgs').hide();
    if(!checkValue($('#cetecerp_ftp_domain')))
    {
        showErrorAndFocus($('#cetecerp_ftp_domain'));
        valid = false;
	}
	
    if(!checkValue($('#cetecerp_ftp_username')))
    {
        showErrorAndFocus($('#cetecerp_ftp_username'));
        valid = false;
	}

    if(!checkValue($('#cetecerp_ftp_password')))
    {
        showErrorAndFocus($('#cetecerp_ftp_password'));
        valid = false;
	}
	
	if(!valid)
    {
        return false;
	}
	
    var account_name = $('#cetecerp_ftp_domain').val();
    $('#mdlcetecerp_ftp_domain').html(account_name);
    $('#mdlCetecERP').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlcetecerp_ftp_domain').empty();
    $('#mdlCetecERP').modal('toggle');
});