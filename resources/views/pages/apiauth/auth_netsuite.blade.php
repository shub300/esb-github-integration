<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/netsuite-logo.png' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST" class="netsuite_connect_form">
                    @csrf
                    <!--<div class="mb-1 hidden">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control"
                            placeholder="Example : Netsuite Account 1" autocomplete="off" data-toggle="tooltip"
                            data-placement="top" title="Any random name for multiple accounts identifier." >
                        <span class="err_msg err_mgs">Field value is required</span>
                    </div>-->
                    <div class="mb-1">
                        <label class="my-label">Netsuite Account ID</label>
                        <input type="text" class="form-control" id="netsuite_account_name"  name="account_name"
                            placeholder="Account" title="" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1 hidden">
                        <label class="my-label">Endpoint</label>
                        <input type="hidden" class="form-control" value="2020_2" id="ns_endpoint" name="ns_endpoint"
                            placeholder="Netsuite Endpoint" >
                        <span class="err_msg err_mgs ns_endpoint_error">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1 hidden">
                        <label class="my-label">Data Center URLs/Host</label>
                        <input type="text" class="form-control" value="https://webservices.netsuite.com" id="ns_host" name="ns_host"
                            placeholder="Host" data-toggle="tooltip" title="For e.g. https://123456789.suitetalk.api.netsuite.com">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    
                    <div class="mb-1">
                        <label class="my-label">Consumer Key</label>
                        <input type="text"  class="form-control"  id="consumer_key" name="consumer_key"
                            placeholder="Consumer Key" title="Netsuite Consumer Key" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Consumer Secret</label>
                        <input type="text"  class="form-control"  id="consumer_secret" name="consumer_secret"
                            placeholder="Consumer Secret" title="Netsuite Consumer Secret" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Token</label>
                        <input type="text"  class="form-control"  id="ns_token" name="ns_token"
                            placeholder="Token" title="Netsuite Token" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Token Secret</label>
                        <input type="text"  class="form-control"  id="ns_token_secret" name="ns_token_secret"
                            placeholder="Token Secret" title="Netsuite Token Secret" data-toggle="tooltip">
                        <span class="err_msg err_mgs">@lang('tagscript.error_msg')</span>
                    </div>

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitNetsuite"
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
<div class="modal fade text-start modal-primary " id="mdlNetsuite" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Netsuite account secrets?</label>
                    <h6><b>Account:&nbsp;</b><small id="mdl_ns_acc_name"></small></h6>  
                    <h6><b>Consumer Key:&nbsp;</b><small id="mdl_ns_key"></small></h6>                  
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectNetsuite()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>


