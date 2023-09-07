<!-- Register v1 -->
<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{env('CONTENT_SERVER_PATH')}}{{'/public/esb_asset/brand_icons/shiphawk.jpg'}}">
            </div>
            <div class="col-sm-8" >
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form class="shiphawk_connect_form" >
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="text" class="form-control" id="account_name" name="account_name" placeholder="Account Name">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Select Environment</label>
                        <select class="form-control" name="environment" id="environment">
                            <option value="sandbox">Sandbox</option>
                            <option value="production">Production</option>
                        </select>
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>
                    <div class="mb-1">
                        <label class="my-label">API Key</label>
                        <input type="text" class="form-control" id="api_key" name="api_key" placeholder="API Key">
                        <span class="err_mgs">@lang('tagscript.error_msg')</span>
                    </div>

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' id="btnSubmitShiphawk">
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
<div class="modal fade text-start modal-primary " id="shiphawkMdl" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">

				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Shiphawk account information?</label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectShiphawk()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_shiphawk.js')}}"></script>