<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/jamesandjames.jpg' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST" id="jamesConnectForm">
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control cred" id="jamesAccountName" name="jamesAccountName"
                            placeholder="James Account Name" autocomplete="off">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Global API key</label>
                        <input type="password" class="form-control cred" id="jamesApiKey" name="jamesApiKey"
                            placeholder="James API Key" autocomplete="off">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Channel API key</label>
                        <input type="password" class="form-control cred" id="jamesChannelApiKey" name="jamesChannelApiKey"
                            placeholder="James Channel API Key" autocomplete="off">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                        <span class=""><i>We don't have any get API to verify channel API key, so make sure this key is valid.</i></span>
                    </div>
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitJames"
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
<div class="modal fade text-start modal-primary " id="mdlJames" tabindex="-1" aria-labelledby="myModalLabelJames" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabelJames">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given James account secrets?</label>
                    <div class="row">
                        <div class="col-sm-4"><h6><b>Account Name:&nbsp;</b></h6></div> <div class="col-sm-8"><small id="mdlJamesAccountName"></small></div>
                        <div class="col-sm-4"><h6><b>Global API Key:&nbsp;</b></h6></div> <div class="col-sm-8"><small id="mdlJamesApiKey"></small></div>
                        <div class="col-sm-4"><h6><b>Template API Key:&nbsp;</b></h6></div> <div class="col-sm-8"><small id="mdlJamesChannelApiKey"></small></div>
                    </div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectJames()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>
<script src="{{asset('public/js/pages/auth_james.js')}}"></script>

