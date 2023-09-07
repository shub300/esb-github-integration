<div class="card mb-0">
    <div class="card-body">
        <form method="POST" class="veracoreform">
            @csrf
            <div class="row">
                <div class="col-sm-4 align-self-center text-center">
                    <img class="icon" style="opacity: 0.5; width: 100%" src="{{ url('public/esb_asset/brand_icons/vercora.jpg') }}">
                </div>
                <div class="col-sm-8">
                    <h4 class="card-title mb-1 text-center">
                        Enter Veracora Authentication Details
                    </h4>
                    @include('flash.flash_message')
                    <div class="mb-1">
                        <label class="my-label">Account Name</label>
                        <input type="url" class="form-control" id="account_name" name="account_name" placeholder="Account Name" >
                        <small class="field_error">Field value is required</small>
                        @error('account_name')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label class="my-label">Owner ID</label>
                        <input type="text" class="form-control" id="ownerID" name="ownerID" placeholder="ownerID" >
                        <small class="field_error">Field value is required</small>
                        @error('ownerID')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label class="my-label">System Id</label>
                        <input type="url" class="form-control" id="custom_domain" name="custom_domain" placeholder="System Id" >
                        <small class="field_error">Field value is required</small>
                        @error('custom_domain')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label class="my-label">Sub Domain</label>
                        <input type="text" class="form-control" id="api_domain" name="api_domain" placeholder="Sub Domain( ***.veracore.com )" >
                        <small class="field_error">Field value is required</small>
                        @error('api_domain')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label class="my-label">Username</label>
                        <input type="text" class="form-control" id="app_id" name="app_id" placeholder="Username" >
                        <small class="field_error">Field value is required</small>
                        @error('app_id')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label class="my-label">Password</label>
                        <input type="password" class="form-control" id="app_secret" name="app_secret" placeholder="Password" >
                        <small class="field_error">Field value is required</small>
                        @error('app_secret')
                            <small class="error">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" class="btn btn-primary waves-effect waves-float waves-light  w-100" data-text='Connect Now' id="btnSubmitVeracore">
                                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
                            </button>
                        </div>
                    </div>
                </div>
               </form>
            </div>
        </div>
    </div>
</div>
<!-- Modal to comfirm account secret submission -->
<div class="modal fade text-start modal-primary" id="mdlVeracore" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered modal-xs">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">
				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given Veracore account secrets?</label>

				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectVeracore()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>
<script src="{{asset('public/js/pages/veracore_auth.js?v='.time())}}"></script>
