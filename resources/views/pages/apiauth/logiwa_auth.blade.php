<div class="card mb-0">
    <div class="card-body">
        <form method="POST" class="Logiwaform" autocomplete="off">
            @csrf
            <div class="row">
                <div class="col-sm-12 align-self-center text-center">
                    @include('flash.flash_message')
                    <h4 class="card-title mb-1 text-center">
                        Enter Logiwa Authentication Details
                    </h4>
                    <img class="icon mb-1" style="opacity: 0.5; width: 200px" src="{{env('CONTENT_SERVER_PATH').'/public/esb_asset/brand_icons/logiwa.png'}}">
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
                    <label class="my-label">Username</label>
                    <input type="text" class="form-control" id="user_name" name="user_name" placeholder="Username" >
                    <small class="field_error">Field value is required</small>
                    @error('user_name')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
                <div class="col-md-6 mb-1">
                    <label class="my-label">Password</label>
                    <input type="password" class="form-control" id="user_password" name="user_password" placeholder="Password" >
                    <small class="field_error">Field value is required</small>
                    @error('user_password')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
                <div class="col-md-6 mb-1">
                    <label class="my-label">Grant Type</label>
                    <input type="text" class="form-control" id="grant_type" name="grant_type" placeholder="Grant Type" value="password" >
                    <small class="field_error">Field value is required</small>
                    @error('grant_type')
                        <small class="error">{{ $message }}</small>
                    @enderror
                </div>
            </div>
            <div class="row pb-1">
                <div class="offset-3 col-xl-6 col-sm-6 col-6 my-2 mb-xl-0 text-center">
                    <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' onclick="validateForm();">
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<button type="button" class="d-none connect-btn" onclick="connect()">Connect</button>
<script>
    function validateForm() {
		valid = true;
		$('.field_error').hide();

        var fieldCheck = [
            'account_name',
            'user_name',
            'user_password',
            'grant_type',
        ];

        $.each(fieldCheck, function(key, value) {
            if (!checkValue($('#'+value))) {
                showErrorAndFocus($('#'+value));
                valid = false;
            }
        });

        if( valid ){
            $(".connect-btn").click();
        } else{
            return valid;
        }
	}

	function showErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().show();
		}
	}

    function hideErrorAndFocus(elem)
    {
		if (elem.length) {
			elem.next().hide();
		}
	}

	function checkValue(elem)
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
		hideErrorAndFocus($('#account_name'));
	});

	$('body').on('change','#user_name',function(){
		hideErrorAndFocus($('#user_name'));
	});

    $('body').on('change','#user_password',function(){
		hideErrorAndFocus($('#user_password'));
	});

    /**
     *
     */
     function connect() {
        pdata = new FormData($(".Logiwaform")[0]);
        showOverlay();
        $('.connect-now').prop('disabled', true);
        $.ajax({
            url: "{{route('logiwa.connect')}}",
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
                    retrievePlatformAccounts('logiwa', 'source');
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
