<?php

namespace App\Http\Controllers;

use App\Models\StokOpname;
use App\Models\DetailObat;
use App\Models\DetailStokOpname;
use App\Models\Admin;
use App\Models\Obat;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class StokopnameController extends Controller
{
    public function index(Request $request)
    {
        // Ambil data stok opname dengan relasi ke detailObat dan admin
        $stokopnames = StokOpname::with(['detailObat', 'admin']);

        // Jika ada filter pencarian berdasarkan id_opname
        if ($request->has('id_opname') && $request->id_opname != '') {
            $stokopnames = $stokopnames->where('id_opname', 'like', '%' . $request->id_opname . '%');
        }

        // Ambil data stok opname yang sudah di-filter
        $stokopnames = $stokopnames->get();

        return view('stokopname.stokopname', compact('stokopnames'));
    }

    public function create()
    {
        // Mengambil data detail obat untuk dropdown
        $detailObats = DetailObat::with('obat')->get();
        $obats = Obat::all();

        // Mengambil ID opname terakhir dan menambahkan 1 pada ID tersebut
        $lastStokOpname = StokOpname::orderByDesc('id_opname')->first();
        $newtIdOpname = $lastStokOpname ? $lastStokOpname->id_opname + 1 : 1;

        // Generate ID detail opname otomatis (misalnya pakai auto-increment terakhir + 1)
        $lastDetailOpname = DetailStokOpname::orderByDesc('id_detailopname')->first();
        $newIdDetailOpname = ($lastDetailOpname ? $lastDetailOpname->id_detailopname + 1 : 1);

        return view('stokopname.createstok', compact('detailObats', 'obats', 'newtIdOpname', 'newIdDetailOpname'));
    }

public function store(Request $request)
{
    try {
        // For debugging purposes - uncomment to see the actual request data
        // dd($request->all());

        DB::beginTransaction();

        // Create the parent StokOpname record first
        // Handle case when auth()->user() might be null
        $adminId = 1; // Default admin ID - change this to an appropriate default value
        if (auth()->check()) {
            $adminId = auth()->user()->id_admin;
        }

        $stokOpname = new StokOpname();
        $stokOpname->id_opname = $request->id_opname;
        $stokOpname->id_detailobat = $request->id_detailobat;
        $stokOpname->id_admin = $adminId;
        $stokOpname->tanggal = \Carbon\Carbon::now('Asia/Jakarta')->toDateString();
        $stokOpname->save();

        // Validate inputs
        $rules = [
            'id_detailopname' => 'required',
            'id_detailobat' => 'required|exists:detail_obat,id_detailobat',
            'stok_fisik' => 'required|integer|min:0',
            'stok_kadaluarsa' => 'required|integer|min:0',
            'keterangan' => 'nullable|string|max:255',
        ];

        // Check which format of the date field exists
        if ($request->has('tanggal_kadaluwarsa')) {
            $rules['tanggal_kadaluwarsa'] = 'required|date';
        } else if ($request->has('tanggal_kadaluarsa')) {
            $rules['tanggal_kadaluarsa'] = 'required|date';
        } else {
            return redirect()->back()->with('error', 'Field tanggal kadaluwarsa tidak ditemukan pada form.')->withInput();
        }

        $validated = $request->validate($rules);

        // Create DetailStokOpname record
        $detailStokOpname = new DetailStokOpname();
        $detailStokOpname->id_detailopname = $validated['id_detailopname'];
        $detailStokOpname->id_opname = $stokOpname->id_opname;
        $detailStokOpname->id_detailobat = $validated['id_detailobat'];

        // Store the actual expired stock count
        $detailStokOpname->stok_kadaluarsa = $validated['stok_kadaluarsa'];

        // Handle date field with either spelling
        if (isset($validated['tanggal_kadaluwarsa'])) {
            $detailStokOpname->tanggal_kadaluarsa = $validated['tanggal_kadaluwarsa'];
        } else if (isset($validated['tanggal_kadaluarsa'])) {
            $detailStokOpname->tanggal_kadaluarsa = $validated['tanggal_kadaluarsa'];
        } else {
            $detailStokOpname->tanggal_kadaluarsa = $request->input('tanggal_kadaluwarsa', $request->input('tanggal_kadaluarsa'));
        }

        $detailStokOpname->keterangan = $validated['keterangan'];
        $detailStokOpname->save();

        // Update inventory in detail_obat and obat tables
        $detailObat = DetailObat::findOrFail($request->id_detailobat);
        $obat = Obat::findOrFail($detailObat->id_obat);

        // Save old stock for calculating difference
        $stokLama = $detailObat->stok;

        // Calculate expired stock count
        $stokKadaluarsa = $request->stok_kadaluarsa;

        // Reduce stock in detail_obat by expired amount
        $stokBaru = $stokLama - $stokKadaluarsa;
        $stokBaru = max(0, $stokBaru); // Ensure non-negative

        // Update stock in detail_obat
        $detailObat->stok = $stokBaru;
        $detailObat->save();

        // Update total stock in obat
        $obat->stok_total = $obat->stok_total - $stokKadaluarsa;
        $obat->save();

        DB::commit();

        return redirect()->route('stokopname.index')->with('success', 'Stok opname berhasil ditambahkan!');
    } catch (\Exception $e) {
        DB::rollBack();
        return redirect()->back()->with('error', 'Gagal menambahkan stok opname: ' . $e->getMessage())->withInput();
    }
}
    public function show($id)
    {
        $stokopname = StokOpname::with([
            'detailStokOpname.detailObat.obat',
            'detailObat',
            'admin'
        ])->findOrFail($id);

        return view('stokopname.detailstok', compact('stokopname'));
    }

    public function edit($id)
    {
        // Ambil data StokOpname dengan eager loading detailStokOpname
        $stokopname = StokOpname::with(['detailStokOpname', 'detailObat.obat', 'admin'])->findOrFail($id);
        $detailStokOpname = DetailStokOpname::where('id_opname', $id)->first();

        if (!$detailStokOpname) {
            return redirect()->route('stokopname.index')->with('error', 'Detail stok opname tidak ditemukan!');
        }

        // Debug: Check if stok_kadaluarsa exists and has a value
        // dd($detailStokOpname->toArray());

        // Ambil data untuk dropdown
        $detailObats = DetailObat::with('obat')->get();
        $obats = Obat::all();

        return view('stokopname.editstok', compact('stokopname', 'detailStokOpname', 'detailObats', 'obats'));
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            // Validasi input form
            $rules = [
                'id_detailobat' => 'required|exists:detail_obat,id_detailobat',
                'stok_fisik' => 'required|integer|min:0', // Validasi stok_fisik
                'stok_kadaluarsa' => 'required|integer|min:0',
                'keterangan' => 'nullable|string|max:255',
            ];

            // Check which format of the date field exists
            if ($request->has('tanggal_kadaluwarsa')) {
                $rules['tanggal_kadaluwarsa'] = 'required|date';
            } else if ($request->has('tanggal_kadaluarsa')) {
                $rules['tanggal_kadaluarsa'] = 'required|date';
            }

            $validated = $request->validate($rules);

            // Get the old detailStokOpname before updating
            $detailStokOpname = DetailStokOpname::where('id_opname', $id)->first();
            $oldStokKadaluarsa = $detailStokOpname ? $detailStokOpname->stok_kadaluarsa : 0;

            // Update StokOpname
            $stokOpname = StokOpname::findOrFail($id);
            $stokOpname->id_detailobat = $validated['id_detailobat'];
            $stokOpname->save();

            // Update DetailStokOpname
            if ($detailStokOpname) {
                $detailStokOpname->id_detailobat = $validated['id_detailobat'];

                // Save the physical stock count input by user
                $detailStokOpname->stok_kadaluarsa = $request->stok_fisik;

                // Handle the date field with either spelling
                if (isset($validated['tanggal_kadaluwarsa'])) {
                    $detailStokOpname->tanggal_kadaluarsa = $validated['tanggal_kadaluwarsa'];
                } else if (isset($validated['tanggal_kadaluarsa'])) {
                    $detailStokOpname->tanggal_kadaluarsa = $validated['tanggal_kadaluarsa'];
                }

                $detailStokOpname->keterangan = $validated['keterangan'];
                $detailStokOpname->save();

                // Update stok di detail_obat
                $detailObat = DetailObat::findOrFail($validated['id_detailobat']);
                $obat = Obat::findOrFail($detailObat->id_obat);

                // Hitung selisih stok baru dan stok lama
                $selisih = $oldStokKadaluarsa - $request->stok_fisik;

                // Update stok di detail_obat
                $detailObat->stok = $request->stok_fisik;
                $detailObat->save();

                // Update stok_total di obat
                $obat->stok_total = $obat->stok_total - $selisih;
                $obat->save();
            }

            DB::commit();

            return redirect()->route('stokopname.index')->with('success', 'Data stok opname berhasil diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memperbarui stok opname: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            // Hapus detail stok opname
            DetailStokOpname::where('id_opname', $id)->delete();

            // Hapus stok opname
            StokOpname::findOrFail($id)->delete();

            DB::commit();

            return redirect()->route('stokopname.index')->with('success', 'Stok opname berhasil dihapus!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal menghapus stok opname: ' . $e->getMessage());
        }
    }
}

