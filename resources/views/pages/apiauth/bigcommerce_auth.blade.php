<!-- Register v1 -->
<div class="card mb-0">
    <div class="card-body">
        <div class="row">
            <div class="col-sm-4">
                <img class="icon brand-logo-file" src="{{env('CONTENT_SERVER_PATH')}}{{'/public/esb_asset/brand_icons/bigcommerce.jpg'}}">
            </div>
            <div class="col-sm-8" >
                <h4 class="card-title mb-1 text-center">
                    Enter Authentication Details
                </h4>
                <form class="bigcommerce_connect_form" >
                    @csrf
                    <div class="mb-1">
                        <label class="my-label">Account Username</label>
                        <input type="text" class="form-control" id="account_name" name="account_name" placeholder="Account Username">
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Access Token</label>
                        <input type="text" class="form-control" id="access_token" name="access_token" placeholder="Access Token">
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Client ID</label>
                        <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID">
                    </div>
                    <div class="mb-1">
                        <label class="my-label">Store Hash</label>
                        <input type="text" class="form-control" id="store_hash" name="store_hash" placeholder="https://api.bigcommerce.com/stores/STORE_HASH/v3/">
                    </div>

                    <div class="row justify-content-center pb-1">
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <button type="button" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now' id="btnSubmitBigcommerce">
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
<div class="modal fade text-start modal-primary " id="bigcommMdl" tabindex="-1" aria-labelledby="myModalLabel160" aria-modal="true" role="dialog">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="myModalLabel160">Connection Confirmation</h5>
				<i type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" data-feather="x"></i>
			</div>
			<div class="modal-body">

				<div class="mb-1">
					<label class="my-label">Are you sure you want to connect with given BigCommerce account information?</label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary waves-effect waves-float waves-light" data-bs-dismiss="modal" onclick="connectBigCommerce()">Connect</button>
                <button type="button" class="btn btn-danger waves-effect waves-float waves-light btn-close" data-bs-dismiss="modal">Cancel</button>
			</div>
		</div>
	</div>
</div>

<script src="{{asset('public/js/pages/auth_bigcommerce.js')}}"></script>