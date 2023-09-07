<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/sitoo.png' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form method="POST" class="sitoo_connect_form">
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">API ID</label>
                        <input type="text" class="form-control" id="sitoo_api_id" name="sitoo_api_id"
                            placeholder="Sitoo API ID">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Password</label>
                        <input type="password" class="form-control" id="sitoo_password" name="sitoo_password"
                            placeholder="Sitoo Password">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Base URL</label>
                        <input type="text" class="form-control" id="sitoo_base_url" name="sitoo_base_url"
                            placeholder="Base URL" autocomplete="off" data-toggle="tooltip"
                            data-placement="top" title="Base url generated in Sitoo." >
                        <span class="err_mgs url_error">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitSitoo"
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
<div class="modal fade text-start modal-primary " id="mdlSitoo" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Sitoo account secrets?</label>
                    <div class="row">
                        <div class="col-sm-3"><h6><b>API ID:&nbsp;</b></h6></div> <div class="col-sm-9"><small id="mdlSitoo_api_id"></small></div>
                        <div class="col-sm-3"><h6><b>Base URL:&nbsp;</b></h6></div> <div class="col-sm-9"><small id="mdlSitoo_base_url"></small></div>
                        <div class="col-sm-3"><h6><b>Site:&nbsp;</b></h6></div>
                        <div class="col-sm-9">
                            <select id="mdlSitoo_site" class="custom-select custom-select-sm form-control form-control-sm">
                                <option value="">-- Select Sitoo Site --</option>
                            </select>
                            <span class="err_mgs">@lang('tagscript.error_msg')</span>
                            <input type="hidden" id="mdlSitoo_password" value="">
                        </div>
                    </div>

				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectSitoo()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>
<script src="{{asset('public/js/pages/auth_sitoo.js')}}"></script>

