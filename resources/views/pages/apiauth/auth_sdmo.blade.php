<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/sdmo.png' }}">
            </div>
            <div class="col-sm-8">
                <h4 class="card-title mb-1 text-center">Enter Authentication Details</h4>
                <form method="POST" class="sdmo_connect_form">
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name<span class="required">*</span></label>
                        <input type="text" class="form-control" id="sdmo_account_name" name="account_name" placeholder="Account Name" />
                        <small class="err_mgs">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Region<span class="required">*</span></label>
                        <select class="form-control" id="sdmo_region" name="region">
                            <option value="">Select Region</option>
                            <option value="eu">Europe</option>
                            <option value="na">US</option>
                        </select>
                        <small class="err_mgs">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Client ID<span class="required">*</span></label>
                        <input type="text" class="form-control" id="sdmo_client_id" name="client_id" placeholder="Client ID" />
                        <small class="err_mgs">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Client Secret<span class="required">*</span></label>
                        <input type="text" class="form-control" id="sdmo_client_secret" name="client_secret" placeholder="Client Secret" />
                        <small class="err_mgs">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Tenant ID<span class="required">*</span></label>
                        <input type="text" class="form-control" id="sdmo_tenant_id" name="tenant_id" placeholder="Tenant ID" />
                        <small class="err_mgs">@lang('tagscript.error_msg')</small>
                    </div>
                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" id="btnSubmitSDMO" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now'>
                                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> 
                                <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal to confirm account secret submission -->
<div class="modal fade text-start modal-primary " id="mdlSDMO" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given SDMO account secrets?</label>
					<h6><b>Account Name:&nbsp;</b><small id="mdlAccountName"></small></h6>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectSDMO()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_sdmo.js')}}"></script>