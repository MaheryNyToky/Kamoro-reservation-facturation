import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';

Future<String> savePdfToDownloads(Uint8List bytes, String filename) async {
  final directory =
      await getDownloadsDirectory() ?? await getApplicationDocumentsDirectory();
  final file = File('${directory.path}${Platform.pathSeparator}$filename');
  await file.writeAsBytes(bytes, flush: true);
  return 'Facture téléchargée dans ${file.path}';
}
