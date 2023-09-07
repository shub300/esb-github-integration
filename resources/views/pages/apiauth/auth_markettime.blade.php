<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/markettime.svg' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST" class="markettime_connect_form">
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="markettime_account_name" name="account_name"
                            placeholder="Example : MarketTime Account 1" autocomplete="off" data-toggle="tooltip"
                            data-placement="top" title="Any random name for multiple accounts identifier." >
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Company ID</label>
                        <input type="text" class="form-control" id="markettime_companyID" name="markettime_companyID"
                            placeholder="MarketTime Company ID">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">API Key</label>
                        <input type="password" class="form-control" id="markettime_api_key" name="markettime_api_key"
                            placeholder="MarketTime API Key">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <!-- <div class="mb-1 row" style="margin-left: 2px;">
                        <label>Sandbox</label>&nbsp;<div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="env_type" name="env_type" checked>
                            <label class="custom-control-label" for="env_type"></label>
                        </div><label>&nbsp;Production</label>
                    </div> -->

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitmarkettime"
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
<div class="modal fade text-start modal-primary " id="mdlMarkettime" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given MarketTime account details?</label>
					<h6><b>Account Name:&nbsp;</b><small id="mdlMarkettime_acc_name"></small></h6>
                    <h6><b>Company ID:&nbsp;</b><small id="mdlMarkettime_acc_companyID"></small></h6>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectMarketTime()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_markettime.js')}}"></script>
