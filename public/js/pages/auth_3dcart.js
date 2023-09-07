var baseUrl = $('body').attr('data-url');
function validateForm(platform)
{
    valid = true;
    $('.field_error').hide();
	if(platform=='3dcart')
	{
        if(!checkvalue($('#store_url')))
		{
            showErrorAndFocus($('#store_url'));
            valid = false;
		} 
	}
	
    if(!valid)
	{
        return false;
	}
	
    $('.connect-now').prop('disabled',true);
    return true;
}

function showErrorAndFocus(elem)
{
    if(elem.length)
	{
        elem.next().show();
	}
}

function checkvalue(elem)
{
	var input = elem.val();
	var regex = /(<([^>]+)>)/ig;
	var validatedInput = input.replace(regex, "");
	if(input != validatedInput){
		return false;
	}

    if(elem.length)
	{
        if(!elem.val().trim())
		{
            return false;
		}
		else
		{
            return true;
		}
	}
}

CreateInterval('3dcart');

function CreateInterval(platform_id) 
{
	checkConnect = setInterval(function(){
		clearInterval(checkConnect);
		
        $.ajax({
            type: 'POST',
            url: baseUrl + "/getConnectedAccountInfo",
            data: {
                '_token': $('meta[name="csrf-token"]').attr('content'), platform_id: platform_id
			},
            beforeSend: function () {
                showOverlay();
			},
            success: function (response) {
                hideOverlay();
                if(response.status_code === 1) 
				{
                    // toastr.success(response.status_text);
				} 
				else 
				{
                    toastr.error(response.status_text);
				}
			},
            error: function (jqXHR, textStatus, errorThrown) {
                hideOverlay();
                if(jqXHR.status == 500)
				{
                    toastr.error('Internal error: ' + jqXHR.responseText);
				} 
				else 
				{
                    toastr.error('Unexpected error Please try again.');
				}
			}
		});
	}, 100);
}