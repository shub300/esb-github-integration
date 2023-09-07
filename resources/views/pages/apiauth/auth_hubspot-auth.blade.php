<div class="card mb-0">
    <div class="card-body">
        <form method="POST" class="HubSpotform" autocomplete="off">
            @csrf
            <div class="row">
                <div class="col-sm-12 align-self-center text-center">
                    @include('flash.flash_message')
                    <h4 class="card-title mb-1 text-center">
                        Enter HubSpot Authentication Details
                    </h4>
                    <img class="icon mb-1" width="10%" src="{{env('CONTENT_SERVER_PATH').'/public/esb_asset/brand_icons/hubspot.png'}}">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-1">
                    <label class="my-label">Account Name</label>
                    <input type="text" class="form-control" id="account_name" name="account_name" placeholder="Account Name" >
                    <small class="field_error">Field value is required</small>
                    @error('account_name')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
            </div>
            <div class="row pb-1">
                <div class="offset-3 col-xl-6 col-sm-6 col-6 my-2 mb-xl-0 text-center">
                    <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' onclick="validateHubSpotForm();">
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<button type="button" class="d-none connect-btn" onclick="connectHubSpot()">Connect</button>
<script>
    function validateHubSpotForm() {
		valid = true;
		$('.field_error').hide();

        var fieldCheck = [
            'account_name',
        ];

        $.each(fieldCheck, function(key, value) {
            if (!checkHubSpotValue($('#'+value))) {
                showHubSpotErrorAndFocus($('#'+value));
                valid = false;
            }
        });

        if ( !isValidHttpUrl( $('#end_point').val() ) ) {
            showHubSpotErrorAndFocus( $('#end_point') );
            valid = false;
        }

        if( valid ){
            $(".connect-btn").click();
        } else{
            return valid;
        }
	}

    /**
     * Check if a JavaScript string is a URL
     */
    function isValidHttpUrl(string) {
        let url;
        try {
            url = new URL(string);
        } catch (_) {
            return false;
        }

        return url.protocol === "http:" || url.protocol === "https:";
    }

	function showHubSpotErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().show();
		}
	}

    function hideHubSpotErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().hide();
		}
	}

	function checkHubSpotValue(elem)
    {
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

    $('body').on('change','#account_name',function(){
		hideHubSpotErrorAndFocus($('#account_name'));
	});


    /**
     *
     */
     function connectHubSpot() {
        pdata = new FormData($(".HubSpotform")[0]);
        showOverlay();
        $('.connect-now').prop('disabled', true);
        $.ajax({
            url: "{{route('HubSpot.connect')}}",
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
                if ( res.end_point != undefined && res.end_point.length == 1 ) {
                    errorNotify(res.end_point[0], 'Failed');
                } else if( res.error ){
                    errorNotify(res.error, 'Failed');
                } else {
                    $('#p2_conn_section').hide();
                    $('#p2_connection_form').empty();
                    retrievePlatformAccounts('HubSpot', 'destination');
                    successNotify( res.success, 'Success');
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
</script>
