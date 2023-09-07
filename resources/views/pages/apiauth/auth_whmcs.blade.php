<div class="card mb-0">
    <div class="card-body">
        <form method="POST" class="WhmCsform" autocomplete="off">
            @csrf
            <div class="row">
                <div class="col-sm-12 align-self-center text-center">
                    @include('flash.flash_message')
                    <h4 class="card-title mb-1 text-center">
                        Enter WhmCs Authentication Details
                    </h4>
                    <img class="icon mb-1" style="opacity: 0.5; width: 200px" src="{{ url('public/esb_asset/brand_icons/whmcs-logo.png') }}">
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
                <div class="col-md-6 mb-1">
                    <label class="my-label">End Point</label>
                    <input type="text" class="form-control" id="end_point" name="end_point" placeholder="WHMCS End Point" >
                    <small class="field_error">Missing URL endpoint value</small>
                    @error('end_point')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
                <div class="col-md-6 mb-1">
                    <label class="my-label">Username</label>
                    <input type="text" class="form-control" id="user_name" name="user_name" placeholder="WHMCS Username" >
                    <small class="field_error">Field value is required</small>
                    @error('user_name')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
                <div class="col-md-6 mb-1">
                    <label class="my-label">Password</label>
                    <input type="password" class="form-control" id="user_password" name="user_password" placeholder="WHMCS Password" >
                    <small class="field_error">Field value is required</small>
                    @error('user_password')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>

            </div>
            <div class="row pb-1">
                <div class="offset-3 col-xl-6 col-sm-6 col-6 my-2 mb-xl-0 text-center">
                    <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' onclick="validateWhmCsForm();">
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<button type="button" class="d-none connect-btn" onclick="connectWhmCs()">Connect</button>
<script>
    function validateWhmCsForm() {
		valid = true;
		$('.field_error').hide();

        var fieldCheck = [
            'account_name',
            'user_name',
            'user_password',
            'end_point',
        ];

        $.each(fieldCheck, function(key, value) {
            if (!checkWhmCsValue($('#'+value))) {
                showWhmCsErrorAndFocus($('#'+value));
                valid = false;
            }
        });

        if ( !isValidHttpUrl( $('#end_point').val() ) ) {
            showWhmCsErrorAndFocus( $('#end_point') );
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

	function showWhmCsErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().show();
		}
	}

    function hideWhmCsErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().hide();
		}
	}

	function checkWhmCsValue(elem)
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
		hideWhmCsErrorAndFocus($('#account_name'));
	});

	$('body').on('change','#user_name',function(){
		hideWhmCsErrorAndFocus($('#user_name'));
	});

    $('body').on('change','#user_password',function(){
		hideWhmCsErrorAndFocus($('#user_password'));
	});

    /**
     *
     */
     function connectWhmCs() {
        pdata = new FormData($(".WhmCsform")[0]);
        showOverlay();
        $('.connect-now').prop('disabled', true);
        $.ajax({
            url: "{{route('whmcs.connect')}}",
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
                    retrievePlatformAccounts('whmcs', 'destination');
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
