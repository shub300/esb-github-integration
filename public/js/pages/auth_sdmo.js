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

function connectSDMO(){
    $('#mdlAccountName').empty();
    $('#mdlSDMO').modal('toggle'); 
    var data = new FormData($(".sdmo_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectSDMOAuth",
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
                retrievePlatformAccounts('sdmo', 'source');
				}else{
                errorNotify(res.status_text,'Failed');
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

$(document.body).on('click', '#btnSubmitSDMO', function(){
    var valid = true;
    $('.err_mgs').hide();
    if(!checkValue($('#sdmo_account_name')))
    {
        showErrorAndFocus($('#sdmo_account_name'));
        valid = false;
	}
	
    if(!checkValue($('#sdmo_client_id')))
    {
        showErrorAndFocus($('#sdmo_client_id'));
        valid = false;
	}
	
	if(!checkValue($('#sdmo_client_secret')))
    {
        showErrorAndFocus($('#sdmo_client_secret'));
        valid = false;
	}
	
	if(!checkValue($('#sdmo_tenant_id')))
    {
        showErrorAndFocus($('#sdmo_tenant_id'));
        valid = false;
	}
	
	if(!checkValue($('#sdmo_region')))
    {
        showErrorAndFocus($('#sdmo_region'));
        valid = false;
	}
	
	if(!valid)
    {
        return false;
	}
	
    var account_name = $('#sdmo_account_name').val();
    $('#mdlAccountName').html(account_name);
    $('#mdlSDMO').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlAccountName').empty();
    $('#mdlSDMO').modal('toggle');
});