<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/magento-logo.png' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST" class="magento_connect_form">
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="account_name"  name="account_name"
                            placeholder="Example : Magento Account 1" autocomplete="off" data-toggle="tooltip"
                            data-placement="top" title="Any random name for multiple accounts identifier." >
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    
                    
                    <div class="mb-1">
                        <label class="my-label">Host</label>
                        <input type="url" class="form-control"  id="mg_host" name="mg_host"
                            placeholder="Your Domain like - https://abc.com" data-toggle="tooltip" title="Your Domain like - https://abc.com">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    
                    
                  <!--  <div class="mb-1">
                        <label class="my-label">Consumer Key</label>
                        <input type="text"  class="form-control"  id="consumer_key" name="consumer_key"
                            placeholder="Consumer Key" title="Magento Consumer Key" data-toggle="tooltip">
                        <span class="err_msg err_mgs">Field value is required</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Consumer Secret</label>
                        <input type="text"  class="form-control"  id="consumer_secret" name="consumer_secret"
                            placeholder="Consumer Secret" title="Magento Consumer Secret" data-toggle="tooltip">
                        <span class="err_msg err_mgs">Field value is required</span>
                    </div> -->
                    <div class="mb-1">
                        <label class="my-label">Access Token</label>
                        <input type="text"  class="form-control"  id="access_token" name="access_token"
                            placeholder="Token" title="Magento Access Token" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                   <!-- <div class="mb-1">
                        <label class="my-label">Access Token Secret</label>
                        <input type="text"  class="form-control"  id="mg_token_secret" name="mg_token_secret"
                            placeholder="Access Token Secret" title="Magento Access Token Secret" data-toggle="tooltip">
                        <span class="err_msg err_mgs">Field value is required</span>
                    </div> -->

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitMagento"
                                class="btn btn-primary waves-effect waves-float waves-light w-100"
                                data-text='Connect Now'>
                                <span class="spinner-border spinner-border-sm loading-icon" role="status"
                                    aria-hidden="true"></span> <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal to comfirm account secret submission -->
<div class="modal fade text-start modal-primary " id="mdlMagento" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Magento account details?</label>
                    <h6><b>Account:&nbsp;</b><small id="mdl_mg_acc_name"></small></h6>  
                    <h6><b>Access Token:&nbsp;</b><small id="mdl_mg_token"></small></h6>                  
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectMagento()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>
