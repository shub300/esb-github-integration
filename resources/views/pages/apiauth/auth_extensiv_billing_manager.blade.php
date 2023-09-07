<div class="card mb-0">
	<div class="card-body">
		<div class="row">
			<div class="col-sm-4">
				<img class="icon brand-logo-file" src="{{ env('CONTENT_SERVER_PATH') }}{{ '/public/esb_asset/brand_icons/extensivbillingmanager.png' }}">
			</div>
			<div class="col-sm-8">
				<h4 class="card-title mb-1 text-center">Enter Extensiv Billing Manager Details</h4>
				<form method="POST" class="extensiv_billing_manager_form">
					@csrf
					<input type='hidden' name='platform_name' value='{{ $platform }}' class="auth_platform_name"/>
					<input type='hidden' name='allow_direct_connection' value='{{ $allow_direct_connection }}' class="allow_direct_connection" />
					<div class="mb-1">
						<label class="my-label">Account Name *</label>
						<input type="text" class="form-control" id="account_name" name="account_name" placeholder="Account Name" value="Billing Manager">
						<small class="field_error" style="color:red !important">Account name is required.</small>
					</div>
					{{--
					<div class="mb-1">
						<label class="my-label">Client ID *</label>
						<input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID" >
						<small class="field_error">@lang('tagscript.error_msg')</small>
					</div>
					<div class="mb-1">
						<label class="my-label">Client Secret *</label>
						<input type="text" class="form-control" id="client_secret" name="client_secret" placeholder="Client Secret" >
						<small class="field_error">@lang('tagscript.error_msg')</small>
					</div>
					--}}
					<div class="row justify-content-center pb-1">
						<div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
							<button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' id="btnSubmitExtensivBillingManagerAccount">
								<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<!-- Modal to comfirm account secret submission -->
{{-- <div class="modal fade text-start modal-primary" id="mdlExtensivBillingManager" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered modal-xs">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Extensiv Billing Manager account details?</label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectExtensivBillingManagerAccount()">Connect</button>
				<button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div> --}}
<script src="{{ asset('public/js/pages/auth_extensiv_billing_manager/auth_extensiv_billing_manager_' .app('App\Utility\JsVersionDefination')::EXTENSIV_BILLING_MANAGER) }}.js"></script>