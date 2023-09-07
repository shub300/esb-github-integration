<!-- Register v1 -->
<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{env('CONTENT_SERVER_PATH')}}{{'/public/esb_asset/brand_icons/wayfair.jpeg'}}">
            </div>
            <div class="col-sm-8" >
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form class="wayfair_connect_form" >
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="wayfair_account_name" name="account_name" placeholder="Example : Wayfair Account 1" autocomplete="off" data-toggle="tooltip" data-placement="top" title="This name will identify your account">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Client ID</label>
                        <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Client Secret</label>
                        <input type="password" class="form-control" id="client_secret" name="client_secret" placeholder="Client Secret">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>

                        <div class="mb-1 row" style="margin-left: 2px;">
                            <label>Sandbox</label>&nbsp;<div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="env_type" name="env_type" checked >
                            <label class="custom-control-label" for="env_type"></label>
                        </div><label>&nbsp;Production</label>
                    </div>

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' id="btnSubmitWayfair">
                                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- /Register v1 -->

<!-- Modal to comfirm account secret submission -->
<div class="modal fade text-start modal-primary " id="mdlWayfair" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">

				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Wayfair account secrets?</label>
                    <br>
					<h6><b>Account Name:&nbsp;</b><small id="mdlWayfair_acc_name"></small></h6>
                    <h6><b>Client ID:&nbsp;</b><small id="mdlWayfair_client_id"></small></h6>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectWayfair()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_wayfair.js')}}"></script>

<script>
    $(function () {
      $('[data-toggle="tooltip"]').tooltip()
    })
</script>