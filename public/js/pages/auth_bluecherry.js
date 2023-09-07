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

function connectBlueCherry(){
    $('#mdlbluecherry_api_url').empty();
    $('#mdlBlueCherry').modal('toggle'); 
    var data = new FormData($(".bluecherry_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectBlueCherryAuth",
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
                retrievePlatformAccounts('bluecherry', 'source');
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

$(document.body).on('click', '#btnSubmitBlueCherry', function(){
    var valid = true;
    $('.err_mgs').hide();
    if(!checkValue($('#bluecherry_api_url')))
    {
        showErrorAndFocus($('#bluecherry_api_url'));
        valid = false;
	}
	
    if(!checkValue($('#bluecherry_subscription_key')))
    {
        showErrorAndFocus($('#bluecherry_subscription_key'));
        valid = false;
	}
	
	if(!valid)
    {
        return false;
	}
	
    var account_name = $('#bluecherry_api_url').val();
    $('#mdlbluecherry_api_url').html(account_name);
    $('#mdlBlueCherry').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlbluecherry_api_url').empty();
    $('#mdlBlueCherry').modal('toggle');
});